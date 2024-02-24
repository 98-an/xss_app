?php 
//header("Access-Control-Allow-Origin: *");
//header("Content-Security-Policy: script-src 'self'");
$file="messages.txt";
$messages=file_get_contents($file);
 ?>
<html>
<head>
 <script>
   function save(){ 
        var callback = function () {     
        var show=document.getElementById("show"); 
        show.innerHTML= xmlhttp.responseText;       
     } ; 
     var input=document.getElementById("message").value;
     var url = "http://localhost/C1/guestbookleavemessage.php?message="+input;
     var xmlhttp = new XMLHttpRequest();            
     xmlhttp.open('GET',url, true);
     xmlhttp.onreadystatechange = callback;
     xmlhttp.send(null);      
 } 
  

 </script>

</head>
<body>
<h1>Welcome to our Guest Book, Leave us a Message! </h1>
<input  id="message" >
<button onclick= 'save()' >Leave a message</button> 
<h2>All the messages left by guests </h2>
<div id="show"><?php echo $messages?> </div>
</body>
</html>

