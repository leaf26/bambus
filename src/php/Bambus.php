<?php
/*
 * Copyright (c) 2013, René Klomp, Edwin Schaap
 * All rights reserved.
 * 
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the organization nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

class Bambus {
    private $x,
            $filename,
            $fd,
            $config,
            $jsonReply=array('log'=>'');

    function __construct($filename, $config=array()) {
        $this->filename = $filename;
        $this->config = array_merge($this->defaultConfig(), $config);

        $this->fd = fopen($this->filename, 'a');
        if($this->fd === false){
            $this->sendReply('error', 'Failed to create or open file'.$php_errormsg);
        }
    }

    private function defaultConfig() {
        return array(
            'tmp_dir'=>sys_get_temp_dir(),
            'reply_log'=>false
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
                $this->sendReply('error', 'X-Start-Byte header not set');
            }else{
                $startByte = $_SERVER['HTTP_X_START_BYTE'];
            }
        }else{
            // user suppplied start byte
        }

        $this->jsonReply['log'] .= "process chunk $chunkFile with sb $startByte\n";
        
        // Lock destination file (blocking)
        if(flock($this->fd, LOCK_EX)){
            try{
                $this->filesize = $this->checkFileSize();

                if($this->filesize > $startByte){
                    $this->sendReply('warning', 'Unable to append chunk. Start Byte is smaller then current filesize');
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
        }else{
            // Lock FAIL
            $this->storeChunk($chunkFile, $startByte);
        }

        $this->sendReply();
    }

    private function appendPendingTempFiles(){
        $tempFiles = $this->getTempFiles();

        foreach($tempFiles as $tempFile){
            if($this->filesize == $tempFile['startByte']){
                $this->filesize += $this->appendChunk($tempFile['name'], $tempFile['startByte']);
                unlink($tempFile['name']);
            }else if($this->filesize > $tempFile['startByte']){
                unlink($tempFile['name']);
            }else{
                // Exit foreach loop: We can never append the rest of the chunks
                return $this->filesize;
            }
        }
    }

    private function getTempFiles() {
        $tempFiles = array();

        // Get all files starting with hash($this->filename).'#' from $this->config['tmp_dir'];
        foreach(glob($this->config['tmp_dir'].'/'.md5($this->filename).'#*') as $tempFile){
            $newTempFile['name'] = $tempFile;
            $newTempFile['startByte'] = end(explode('#', $tempFile));
            $tempFiles[] = $newTempFile;
        }

        usort($tempFiles, array($this, 'startByteSort'));
        return $tempFiles;
    }

    private function startByteSort($a, $b){
        if ($a['startByte'] == $b['startByte']) {
            return 0;
        }
        return ($a['startByte'] < $b['startByte']) ? -1 : 1;
    }

    /* 
     * Modified checkFileSize from FileSender Project www.filesender.org
     * 
     * Copyright (c) 2009-2012, AARNet, Belnet, HEAnet, SURFnet, UNINETT
     * All rights reserved.
     */
    public function checkFileSize() {
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
        if($this->filesize == $startByte){
            $this->filesize += $this->appendChunk($chunkFile);
            return true;
        }

        return false;
    }

    private function appendChunk($chunkFile) {
        $this->jsonReply['log'] .= "Append Chunk: $chunkFile\n";
        $ifd = fopen($chunkFile, 'r');
        $written = 0;
        while($data = fread($ifd, 1000000)){
            $written += fwrite($this->fd, $data) or $this->sendReply('error', 'Error appending chunk');
        }
        fclose($ifd);
        
        return $written;
    }

    private function storeChunk($chunkFile, $startByte) {
        $this->jsonReply['log'] .= "Store Chunk\n";
        $ifd = fopen($chunkFile, 'r');
        $ofd = fopen($this->config['tmp_dir'].'/pre.'.md5($this->filename).'#'.$startByte, 'w+');
        
        $written = 0;
        while($data = fread($ifd, 1000000)){
            $written += fwrite($ofd, $data) or $this->sendReply('error', 'Error storing chunk to temp');
        }
        fclose($ifd);
        fclose($ofd);

        // Rename pre. This is done to prevent other thread from reading while writing is not complete
        rename($this->config['tmp_dir'].'/pre.'.md5($this->filename).'#'.$startByte,
               $this->config['tmp_dir'].'/'.md5($this->filename).'#'.$startByte);

        return $written;
    }

    private function sendReply($status='ok', $message=''){
        $this->jsonReply['status'] = $status;
        $this->jsonReply['message'] =  $message;
        $this->jsonReply['filesize'] = $this->filesize;

        if(!$this->config['reply_log']){
            unset($this->jsonReply['log']);
        }

        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        header('Content-type: application/json');
        echo json_encode( $this->jsonReply);
        die();
    }

}
