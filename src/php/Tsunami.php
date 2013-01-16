<?php
/*
 * Tsunami PHP Class
 * Created by Edwin Schaap and René Klomp
 */

class Tsunami {
    private $x,
            $filename,
            $fd,
            $config=array('tmp_dir'=>sys_get_temp_dir());

    function __construct($filename, $config=array()) {
        $this->filename = $filename;
        array_merge($this->config, $config);

        $this->fd = fopen($this->filename, 'a');
        if($this->fd === false){
            return false;
        }
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
                // OK header is set. We can continue.
            }
        }else{
            // user suppplied start byte
        }

        // Lock destination file
        if(flock($this->fd, LOCK_EX)){
            try{
                $this->filesize = $this->checkFileSize();
                
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
            $this->storeChunk($chunkFile, $startByte);
        }
    }

    private function appendPendingTempFiles(){
        $tempfiles = $this->getTempFiles();

        foreach($tempfiles as $tempfile){
            if($this->filesize+1 == $tempfile['startByte']){
                $this->filesize += $this->appendChunk($tempfile['name'], $tempfile['startByte']);
                unlink($tempfile['name']);
            }else{
                // Exit foreach loop: We can never append the rest of the chunks
                return $this->filesize;
            }
        }
    }

    private function getTempFiles() {
        //get alls files starting with hash($this->filename).'#' from $this->config['tmp_dir'];
        return glob($this->config['tmp_dir'].md5($this->filename).'#*');
    }

    /* 
     * checkFileSize from FileSender Project www.filesender.org
     * 
     * Copyright (c) 2009-2012, AARNet, Belnet, HEAnet, SURFnet, UNINETT
     * All rights reserved.
     */
    private function checkFileSize() {
        $fileLocation = $this->filename;
        if (file_exists($fileLocation)) {
            //We should turn this into a switch/case, exhaustive with a default case
            if (PHP_OS == "Darwin") {
                $size = trim(shell_exec("stat -f %z ". escapeshellarg($fileLocation)));
            }
            else if (!(strtoupper(substr(PHP_OS, 0, 3)) == 'WIN'))
            {
                $size = trim(shell_exec("stat -c%s ". escapeshellarg($fileLocation)));
            }
            else {
                     $fsobj = new COM("Scripting.FileSystemObject");
                     $f = $fsobj->GetFile($fileLocation);
                     $size = $f->Size;
            }
            return $size;
        } else {
            return 0;
        }
    }

    private function tryAppendChunk($chunkFile, $startByte){
        if($this->filesize+1 == $startByte){
            $this->filesize += $this->appendChunk($chunkFile);
            return true;
        }

        return false;
    }

    private function appendChunk($chunkFile) {
        $ifd = fopen($chunkFile, 'r')
        $written = 0;
        while($data = fread($ifd, 1000000)){
            $written += fwrite($this->fd, $data) or die("Error");
        }
        fclose($ifd);
        
        return $written;
    }

    private function storeChunk($chunkFile, $startByte) {
        $ifd = fopen($chunkFile, 'r')
        $ofd = fopen($this->config['tmp_dir'].md5($this->filename).'#'.$startByte. 'w+');
        $written = 0;
        while($data = fread($ifd, 1000000)){
            $written += fwrite($ofd, $data) or die("Error");
        }
        fclose($ifd);
        fclose($ofd);

        return $written;
    }

}
