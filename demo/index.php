<!DOCTYPE html>
<html>
  <head>
    <title>Tsunami Uploader</title>
    <meta charset="utf-8" />
    <link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.2.2/css/bootstrap-combined.min.css" rel="stylesheet">

    <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.9.0/jquery.min.js"></script>

    <style type="text/css">
      body {
        padding-top: 20px;
        padding-bottom: 40px;
      }
      /* Custom container */
      .container-small {
        margin: 0 auto;
        max-width: 700px;
      }
      .container-narrow > hr {
        margin: 30px 0;
      }

    </style>
  </head>
  <body>
    <div class="container-small">

      <div class="jumbotron">
        <h1>Tsunami Uploader</h1>
        <p class="lead">Tsunami provides faster file uploading by using HTML5 webworkers.</p>
      </div>

      <hr>

      <div class="row-fluid">
        <div class="span12">
          <h3>Demo</h3>

          <script src="/tsunami.js"></script>

          <div class="demo">
            <input type="file" id="fileUpload" name="files[]" style="display:none"/>
            <div class="input-append">
              <input id="filename" class="input-large" type="text">
              <a class="btn" onclick="$('#fileUpload').click()">Browse</a>
            </div>
          </div>

          <script type="text/javascript">
            function handleFileSelect(e) {
              $('#filename').val(e.target.value);
              
              var files = e.target.files;
          
              var fs = new Tsunami({
                uri: '/',
                simultaneousUploads: 10,
                chunkSize: 5*1024*1024
              });
              fs.addFiles(files);
              fs.upload();
            }
            document.getElementById('fileUpload').addEventListener('change', handleFileSelect, false);
          </script>
        </div>
      </div>
    </div>
  </body>
</html>