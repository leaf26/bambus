<?php
require '../src/php/Tsunami.php';

$fs = new Tsunami('target.file');
$fs->processChunk();