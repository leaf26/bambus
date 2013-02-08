<?php
require '../src/php/Bambus.php';

$fs = new Bambus('target.file');
$fs->processChunk();
