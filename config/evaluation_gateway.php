<?php

// Independent opt-in. This is a loopback diagnostic adapter, not the native game API.
return ['enabled' => env('NL_EVALUATION_GATEWAY', false)];
