var Tsunami = function(opts) {
    if ( !(this instanceof Tsunami)) {
        return new Tsunami(opts);
    }
    
    var $ = this;
    $.defaults = {
        chunkSize: 1*1024*1024,
        simultaneousUploads: 3,
        target: '/',
        workerFile: 'tsunami_worker.js'
    };
    
    
    // useful stuff
    $.isUploading = false;
    $.workers = [];
    $.files = [];
    $.currentStartByte = 0;
    $.currentFile;
    
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
        // Init workers
        $.setupWorkers();
    };
    
    
    $.setupWorkers = function() {
        console.log('Seting up workers');
        var workerHandler = function(e) {
            var data = e.data;
            if(!data.cmd)
                return;
            
            
            switch (data.cmd) {
                case 'ready':
                    // does it have a file already?
                    if(data.hasFile == false)
                        //console.log(e);
                        e.srcElement.postMessage({
                            'cmd':'setFile',
                            'file': $.currentFile
                        })
                    // upload next chunk
                    if($.currentStartByte < $.currentFile.size){
                        var endByte = $.currentStartByte + $.opts.chunkSize;
                        if($.currentFile.size - endByte < $.opts.chunkSize)
                            endbyte = $.currentFile.size;
                        
                        e.srcElement.postMessage({
                            'cmd':'uploadChunk',
                            'startByte': $.currentStartByte,
                            'endByte': endByte
                        });
                        $.currentStartByte = endByte;
                    } else {
                        // @TODO: move to next file
                        $.isUploading = false;
                        console.log('Upload done')
                    }
                    break;
                case 'log':
                    console.log('Worker said: '+data.message);
                        
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
            console.log('Worker '+num+" is created");
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