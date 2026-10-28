'use strict';

/**
 * G8E evaluation-only lifecycle adapter for the pinned community Battle class.
 * No listener port, identity provider, wallet writer, PvE verifier, or asset loader.
 * Damage/CP/effects remain inherited; the versioned turn envelope is NOT legacy-wire compatible.
 */
module.exports = function createTurnSafeBattle(LegacyBattle) {
  if (typeof LegacyBattle !== 'function') throw new TypeError('Pinned Battle class required');
  return class TurnSafeBattle extends LegacyBattle {
    constructor(options) {
      if (!options || options.evaluation !== true || options.room?.mode !== 'evaluation' || options.room.allowScrolls) {
        throw new Error('Evaluation duel only; scroll inventory and live rewards are not integrated');
      }
      const ids = [options.host?.character?.id, options.enemy?.character?.id];
      if (ids.some(id => !Number.isSafeInteger(id) || id < 1) || ids[0] === ids[1] || options.host.socket === options.enemy.socket) {
        throw new Error('Two distinct trusted participants required');
      }
      for (const p of [options.host, options.enemy]) {
        if (!p.socket || typeof p.socket.on !== 'function' || typeof p.socket.off !== 'function' || typeof p.socket.emit !== 'function') throw new Error('Duplex socket contract required');
        for (const field of ['level', 'point_earth', 'point_water', 'point_wind', 'point_lightning', 'point_fire']) {
          if (!Number.isSafeInteger(p.character[field]) || p.character[field] < (field === 'level' ? 1 : 0) || p.character[field] > 10000) throw new Error('Unvalidated character stats');
        }
        const skills = p.character.equipment_skills;
        if (typeof skills !== 'string' || skills.length > 1000 || skills.split(',').filter(Boolean).some(id => !/^skill_[A-Za-z0-9_-]{1,80}$/.test(id))) throw new Error('Unvalidated skill loadout');
      }
      const clock = options.clock;
      if (!clock || ['now', 'setTimeout', 'clearTimeout', 'setImmediate'].some(k => typeof clock[k] !== 'function')) throw new Error('Trusted clock required');
      super(options);
      this.clock = clock;
      this.phase = 'created';
      this.turnId = 0;
      this.terminal = null;
      this.startedOnce = false;
      this.pendingWinner = null;
      this.initialTimer = null;
      this.endTimer = null;
      this.turnDeadline = null;
      this.onEndError = false;
    }

    _bindSocketListeners(p) {
      this.ownBindings ??= [];
      const actions = {weapon: '_handleWeapon', skill: '_handleSkill', dodge: '_handleDodge', charge: '_handleCharge', run: '_handleRun'};
      for (const [action, method] of Object.entries(actions)) {
        this._listen(p.socket, `Battle.action.${action}`, data => {
          const fields = action === 'skill' ? ['battle_id', 'turn_id', 'skill_id'] : ['battle_id', 'turn_id'];
          if (!this._envelope(data, fields) || !this.running || this.phase !== 'input' || this._activeParticipant !== p || p.stats.hp <= 0) return;
          if (action === 'skill' && (typeof data.skill_id !== 'string' || !/^skill_[A-Za-z0-9_-]{1,80}$/.test(data.skill_id))) return;
          this.phase = 'resolving';
          this.animationActor = p;
          this.animationTurn = this.turnId;
          try { this[method](p, data.skill_id); }
          catch { this.dispose('engine_error'); }
        });
      }
      this._listen(p.socket, 'Battle.action.finished', data => {
        if (!this._envelope(data, ['battle_id', 'turn_id']) || this.phase !== 'animation' || p !== this.animationActor || this.animationTurn !== data.turn_id) return;
        this._completeAnimation();
      });
      // Client-supplied timeout/scroll/winner, chat and info RPCs are deliberately not bound.
    }

    _listen(socket, event, fn) {
      socket.on(event, fn);
      this.ownBindings.push({socket, event, fn});
    }

    _envelope(data, fields) {
      return data !== null && typeof data === 'object' && !Array.isArray(data)
        && Object.keys(data).length === fields.length && Object.keys(data).every(k => fields.includes(k))
        && data.battle_id === this.id && Number.isSafeInteger(data.turn_id) && data.turn_id === this.turnId;
    }

    start() {
      if (this.startedOnce || this.phase !== 'created') return false;
      this.startedOnce = true;
      this.running = true;
      this.phase = 'starting';
      const packet = {battleId: this.id, background: this.stage, hostId: this.host.character.id, enemyId: this.enemy.character.id, protocol: 'nl-duel-eval-v1', rewards_issued: false};
      this.host.socket.emit('Battle.started', {...packet, isHost: true});
      this.enemy.socket.emit('Battle.started', {...packet, isHost: false});
      this._sendUpdateInfo(this.host);
      this._sendUpdateInfo(this.enemy);
      this.initialTimer = this.clock.setTimeout(() => { this.initialTimer = null; this._nextTurn(); }, 1000);
      return true;
    }

    _nextTurn() {
      if (!this.running || ['ending', 'ended', 'cancelled'].includes(this.phase)) return;
      this.phase = 'preparing';
      this.turnId++;
      this.turnDeadline = null;
      super._nextTurn();
    }

    _startTurnTimer(p) {
      this._stopTurnTimer();
      this.phase = 'input';
      // An invalid skill must not buy a new deadline by restarting the inherited timer.
      this.turnDeadline ??= this.clock.now() + 32000;
      const turn = this.turnId;
      this._turnTimer = this.clock.setTimeout(() => {
        this._turnTimer = null;
        if (this.running && this.phase === 'input' && this.turnId === turn && this._activeParticipant === p) this._handleTimeout(p);
      }, Math.max(0, this.turnDeadline - this.clock.now()));
    }

    _stopTurnTimer() {
      if (this._turnTimer != null) this.clock.clearTimeout(this._turnTimer);
      this._turnTimer = null;
    }

    _handleTimeout(p) {
      if (!this.running || this.phase !== 'input' || this._activeParticipant !== p) return;
      this.phase = 'preparing';
      super._handleTimeout(p);
    }

    _startAnimationTimer() {
      this._stopAnimationTimer();
      this.animationActor = this._activeParticipant;
      this.animationTurn = this.turnId;
      this.phase = 'animation';
      this._animationTimer = this.clock.setTimeout(() => this._completeAnimation(), 40000);
    }

    _stopAnimationTimer() {
      if (this._animationTimer != null) this.clock.clearTimeout(this._animationTimer);
      this._animationTimer = null;
    }

    _completeAnimation() {
      if (!this.running || this.phase !== 'animation') return;
      this._awaitingFinished = false;
      this._stopAnimationTimer();
      this._nextTurn();
    }

    _scheduleEndAfterAnimation(winner) {
      if (!this.running || this.phase === 'ending') return;
      this.pendingWinner = winner;
      this.phase = 'ending';
      this._awaitingFinished = false;
      this._stopTurnTimer();
      this._stopAnimationTimer();
      this.endTimer = this.clock.setTimeout(() => { this.endTimer = null; this._endBattle(winner, 'battle'); }, 2000);
    }

    _endBattle(winner, reason) {
      if (!this.running || this.terminal) return this.terminal;
      winner = this.pendingWinner ?? winner;
      if (winner !== this.host && winner !== this.enemy) return this.dispose('invalid_terminal');
      this.running = false;
      this.phase = 'ended';
      this._cleanup();
      this.terminal = Object.freeze({battle_id: this.id, winner_id: winner.character.id, reason, turn_id: this.turnId, evaluation_only: true, rewards_issued: false});
      for (const p of [this.host, this.enemy]) p.socket.emit('Battle.ended', {...this.terminal, id: p.character.id, won: p === winner});
      // NEVER call inherited _endBattle/_saveBattle: they write legacy PvP balances directly.
      try { this.onEnd?.(this); } catch { this.onEndError = true; }
      return this.terminal;
    }

    handleDisconnect(socket) {
      if (!this.running) return;
      const p = socket === this.host.socket ? this.host : socket === this.enemy.socket ? this.enemy : null;
      if (p) this._endBattle(this.pendingWinner ?? this._getOpponent(p), this.pendingWinner ? 'battle' : 'disconnect');
    }

    _broadcastAll(event, data) { super._broadcastAll(event, {...data, battle_id: this.id, turn_id: this.turnId}); }

    _cleanup() {
      this._stopTurnTimer();
      this._stopAnimationTimer();
      for (const timer of [this.initialTimer, this.endTimer]) if (timer != null) this.clock.clearTimeout(timer);
      this.initialTimer = this.endTimer = null;
      for (const {socket, event, fn} of this.ownBindings) socket.off(event, fn);
      this.ownBindings = [];
    }

    dispose(reason = 'cancelled') {
      if (this.terminal || this.phase === 'cancelled') return;
      this.running = false;
      this.phase = 'cancelled';
      this._cleanup();
      this.cancelReason = reason;
    }
  };
};
