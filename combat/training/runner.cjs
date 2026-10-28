'use strict';
const {replay} = require('./TrainingEngine.cjs');
let input = ''; process.stdin.setEncoding('utf8');
process.stdin.on('data', chunk => { input += chunk; if (Buffer.byteLength(input) > 1048576) process.exit(2); });
process.stdin.on('end', () => {
  try { process.stdout.write(JSON.stringify(replay(JSON.parse(input)))); }
  catch { process.stderr.write('Training replay rejected\n'); process.exitCode = 2; }
});
