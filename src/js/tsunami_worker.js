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
 *     * Neither the name of the <organization> nor the
 *       names of its contributors may be used to endorse or promote products
 *       derived from this software without specific prior written permission.
 * 
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL <COPYRIGHT HOLDER> BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

var status = 'stopped';
var file = null;
var uri;
var func;
var wid;

self.addEventListener('message' , function(e) {
    var data = e.data;
    
    if(!data.cmd)
        return;
    
    switch (data.cmd) {
        case 'setFile':
            file = data.file;
            func = (file.slice ? 'slice' : (file.mozSlice ? 'mozSlice' : (file.webkitSlice ? 'webkitSlice' : 'slice')));
            postMessage({
                'cmd':'log',
                'message':'File set on worker '+wid
            });
            break;
        case 'setUri':
            uri = data.uri;
            postMessage({
                'cmd':'log',
                'message':'Uri set on worker '+wid
            });
            break;
        case 'start':
            postMessage({
                'cmd':'log',
                'message':'Starting '+wid
            });
            // Message that we're ready
            postMessage({
                'cmd': 'ready',
                'hasFile': (file == "undefined")
            });
            break;
        case 'uploadChunk':
            postMessage({
                'cmd':'log',
                'message':wid+': start from '+data.startByte+' till '+data.endByte
            });
            uploadChunk(data.startByte,data.endByte);
            break;
        case 'setId':
            wid = data.id;
            break;
    }
});


function uploadChunk(startByte, endByte)
{   
    var uploaded = 0;
    
    var blob = file[func](startByte, endByte);
    
    var xhr = new XMLHttpRequest();
    xhr.onreadystatechange = processReqChange;
    //xhr.onprogress = updateProgress;
    xhr.open("POST", uri, true); //Open a request to the web address set
    xhr.setRequestHeader("Content-Disposition"," attachment; name='fileToUpload'"); 
    xhr.setRequestHeader("Content-Type", "application/octet-stream");
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('X-Start-Byte', startByte);
    xhr.setRequestHeader('X-File-Size', file.size);
    //Set up the body of the POST data includes the name & file data.
    xhr.send(blob);

    function updateProgress(e){
        postMessage({
            'cmd' : 'progress',
            'uploaded': (e.loaded - uploaded)
        });
        uploaded = e.loaded;
    }

    function processReqChange(){
        if (xhr.readyState == 4) {
            if (xhr.status == 200) {
                if(xhr.responseText == "ErrorAuth")
                {
                    return;			
                }
                postMessage({
                   'cmd': 'progress',
                   'uploaded': endByte - startByte
                });
                postMessage({
                    'cmd':'ready'
                });
            } else {
                postMessage({
                    'cmd':'error',
                    'message': 'There was a problem retrieving the data:\n' + xhr.statusText
                });
            }
        }else{
        //
        }
    }
}