var Tsunami = function(opts) {
    if ( !(this instanceof Tsunami)) {
        return new Tsunami(opts);
    }
    
    var $ = this;
    $.defaults = {
        chunkSize: 1*1024*1024,
        simultaneousUploads: 3,
        target: '/',
        workerFile: 'tsunami_worker.js',
        log: false
    };
    
    
    // useful stuff
    $.isUploading = false;
    $.workers = [];
    $.files = [];
    $.currentStartByte = 0;
    $.workersInProgress = 0;
    
    
    $h = {
        each: function(o,callback){
            if(typeof(o.length)!=='undefined') {
                for (var i=0; i<o.length; i++) {
                    // Array or FileList
                    if(callback(o[i])===false) return;
                }
            } else {
                for (i in o) {
                    // Object
                    if(callback(i,o[i])===false) return;
                }
            }
        }
    }
    
    $.addFiles = function(files) {
        $.files = files;
    }
    
    $.upload = function(){
        // Make sure we don't start too many uploads at once
        if($.isUploading) return;

        if($.files.lenght == 0) return;
        $.currentFile = $.files[0];

        $.isUploading = true;

        // check if we can resume the file
        // and then start the workers
        var xhr = new XMLHttpRequest();
        xhr.onreadystatechange = processReqChange;
        function processReqChange(){
            if (xhr.readyState == 4) {
                if (xhr.status == 200) {

                    var response = JSON.parse(xhr.responseText);
                    $.currentStartByte = response.filesize;
                    // Start workers
                    $.setupWorkers();

                }
            }
        };
        xhr.open("POST", $.opts.uri, true); //Open a request to the web address set
        xhr.setRequestHeader("Content-Disposition"," attachment; name='fileToUpload'"); 
        xhr.setRequestHeader("Content-Type", "application/octet-stream");
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-Start-Byte', 0);
        xhr.setRequestHeader('X-File-Size', $.currentFile.size);
        xhr.send();
    };
    
    
    $.setupWorkers = function() {
        $.log('Seting up workers');
        var workerHandler = function(e) {
            var data = e.data;
            if(!data.cmd)
                return;
            
            
            switch (data.cmd) {
                case 'ready':
                    $.workersInProgress--;
                    // does it have a file already?
                    if(data.hasFile == false) {
                        //console.log(e);
                        e.target.postMessage({
                            'cmd':'setFile',
                            'file': $.currentFile
                        })
                    }
                    // upload next chunk
                    if($.currentStartByte < $.currentFile.size){
                        var endByte = $.currentStartByte + $.opts.chunkSize;
                        if($.currentFile.size - endByte < $.opts.chunkSize) {
                            endByte = $.currentFile.size;
                        }
                        
                        e.target.postMessage({
                            'cmd':'uploadChunk',
                            'startByte': $.currentStartByte,
                            'endByte': endByte
                        });
                        $.workersInProgress++;
                        $.currentStartByte = endByte;
                    } else {
                        // @TODO: move to next file
                        $.isUploading = false;
                        $.log('Upload done');
                        
                        // Check if all workers are done
                        // and send empty chunk to let the server
                        // clean up
                        if($.workersInProgress == 0) {
                            e.target.postMessage({
                                'cmd':'uploadChunk',
                                'startByte': $.currentStartByte,
                                'endByte': $.currentStartByte
                            });
                        }
                    }
                    break;
                case 'log':
                    $.log('Worker said: '+data.message);
                        
            }
        }
        
        for (var num=1; num<=$.opts.simultaneousUploads; num++) {
            var worker = new Worker($.opts.workerFile);
            worker.onmessage = workerHandler;
            worker.id = num;
            worker.postMessage({
                'cmd':'setId',
                'id' : num
            })
            worker.postMessage({
                'cmd':'setUri',
                'uri':$.opts.uri
            });
            worker.postMessage({
                'cmd':'start'
            });
            $.workers.push(worker);
            $.log('Worker '+num+" is created");
            $.workersInProgress++;
        }
    }    

    $.log = function(message) {
        if($.opts.log){
            console.log(message);
        }
    }
    
    // Settings
    $.opts = opts||{};
    
    // Set default values
    $h.each($.defaults, function(key,value){
        if(typeof($.opts[key])==='undefined') $.opts[key] = value;
    });
    // Return object
    return(this);
}