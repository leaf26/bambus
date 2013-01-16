<?php
/*
 * Tsunami PHP Class
 * Created by Edwin Schaap and René Klomp
 */

class Tsunami {
    private $x,
            $filename,
            $fd,
            $config;

    function __construct($filename, $config=array()) {
        $this->filename = $filename;
        $this->config = array_merge($this->defaultConfig(), $config);

        $this->fd = fopen($this->filename, 'a');
        if($this->fd === false){
            die('Failed to create or open file');
        }
    }

    private function defaultConfig() {
        return array(
            'tmp_dir'=>sys_get_temp_dir()
        );
    }

    public function processChunk($chunkFile = null, $startByte = null) {
        if($chunkFile == null){
            $chunkFile = 'php://input';
        }else{
            // user supplied file for chunk
        }

        if($startByte == null){
            if(!isset($_SERVER['HTTP_X_START_BYTE'])){
                header($_SERVER['SERVER_PROTOCOL'] . ' 500 Internal Server Error', true, 500);
                die('X-Start-Byte header not set');
            }else{
                $startByte = $_SERVER['HTTP_X_START_BYTE'];
            }
        }else{
            // user suppplied start byte
        }

        echo "process chunk $chunkFile with sb $startByte\n";
        
        // Lock destination file
        if(flock($this->fd, LOCK_EX)){
            try{
                $this->filesize = $this->checkFileSize();

                if($this->filesize > $startByte){
                    die('Unable to append chunk. Start Byte is smaller then current filesize');
                }
                
                if(!$this->tryAppendChunk($chunkFile, $startByte)){
                    $this->appendPendingTempFiles();

                    // Retry appending our chunk
                    if(!$this->tryAppendChunk($chunkFile, $startByte)) {
                        $this->storeChunk($chunkFile, $startByte);
                    }
                }
            }
            // Substitution for finally which is only available since PHP 5.5
            catch(Exception $e){
                // Release the lock
                flock($this->fd, LOCK_UN);

                // Forward exception
                throw $e;
            }
            // Release the lock
            flock($this->fd, LOCK_UN);

            return $this->filesize;
        }else{
            // Lock FAIL
            echo "Lock Fail\n";
            $this->storeChunk($chunkFile, $startByte);
        }
    }

    private function appendPendingTempFiles(){
        $tempFiles = $this->getTempFiles();

        foreach($tempFiles as $tempFile){
            echo "checkTempFile\n";
            if($this->filesize == $tempFile['startByte']){
                echo "appendTempFile\n";
                $this->filesize += $this->appendChunk($tempFile['name'], $tempFile['startByte']);
                unlink($tempFile['name']);
            }else{
                // Exit foreach loop: We can never append the rest of the chunks
                return $this->filesize;
            }
        }
    }

    private function getTempFiles() {
        echo "getTempFiles\n";
        $tempFiles = array();

        //get alls files starting with hash($this->filename).'#' from $this->config['tmp_dir'];
        foreach(glob($this->config['tmp_dir'].'/'.md5($this->filename).'#*') as $tempFile){
            $newTempFile['name'] = $tempFile;
            $newTempFile['startByte'] = end(explode('#', $tempFile));
            $tempFiles[] = $newTempFile;
        }

        var_dump(glob($this->config['tmp_dir'].'/'.md5($this->filename).'#*'));
        var_dump($tempFiles);

        return $tempFiles;
    }

    /* 
     * Modified checkFileSize from FileSender Project www.filesender.org
     * 
     * Copyright (c) 2009-2012, AARNet, Belnet, HEAnet, SURFnet, UNINETT
     * All rights reserved.
     */
    private function checkFileSize() {
        if (file_exists($this->filename)) {
            //We should turn this into a switch/case, exhaustive with a default case
            if (PHP_OS == "Darwin") {
                $size = trim(shell_exec("stat -f %z ". escapeshellarg($this->filename)));
            }
            else if (!(strtoupper(substr(PHP_OS, 0, 3)) == 'WIN')) {
                $size = trim(shell_exec("stat -c%s ". escapeshellarg($this->filename)));
            }
            else {
                $fsobj = new COM("Scripting.FileSystemObject");
                $f = $fsobj->GetFile($this->filename);
                $size = $f->Size;
            }
            return $size;
        } else {
            return 0;
        }
    }

    private function tryAppendChunk($chunkFile, $startByte){
        echo $this->filesize .'=='. $startByte."\n";
        if($this->filesize == $startByte){
            $this->filesize += $this->appendChunk($chunkFile);
            return true;
        }

        return false;
    }

    private function appendChunk($chunkFile) {
        echo "Append Chunk: $chunkFile\n";
        $ifd = fopen($chunkFile, 'r');
        $written = 0;
        while($data = fread($ifd, 1000000)){
            $written += fwrite($this->fd, $data) or die("Error appending chunk");
        }
        fclose($ifd);
        
        return $written;
    }

    private function storeChunk($chunkFile, $startByte) {
        echo "Store Chunk\n";
        $ifd = fopen($chunkFile, 'r');
        $ofd = fopen($this->config['tmp_dir'].'/'.md5($this->filename).'#'.$startByte, 'w+');
        echo $this->config['tmp_dir'].'/'.md5($this->filename).'#'.$startByte;
        $written = 0;
        while($data = fread($ifd, 1000000)){
            $written += fwrite($ofd, $data) or die("Error storing chunk to temp");
        }
        fclose($ifd);
        fclose($ofd);

        return $written;
    }

}
