<?php
$str = 'Here is the result: [ {"id": 1, "text": "a {b} c"}, { "id": 2 } ]';
preg_match_all('/\{(?:[^{}]|(?0))*\}/s', $str, $matches);
var_export($matches[0]);
