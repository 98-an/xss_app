# XSS-challenges
This repository is a Dockerized php application containing some XSS vulnerability challenges.<br>
The ideas behind challenges are:</br>
* Javascript validation bypass
* html entities bypass
* WAF bypass
* Black-list validation bypass
* Basic XSS validation bypass
* Double encode bypass of WAF to exploit XSS
* Exploiting XSS by bypassing escape characters


# Run this image
To run this image you need <a href="https://docs.docker.com/install">docker</a> installed.</br>
Then run the command:</br>
```docker run -d -p 8008:80 moeinfatehi/xss_vulnerability_challenges```</br></br>
Help:
```
-d: detached mode (You can use terminal after running command
-p: specifies port (you can change 8008 to whatever you want. If you don't have a web server on your host, set it to 80)
```
</br>
Then request localhost:8008 to access the challenges.</br></br>

<img src="https://i.imgur.com/UTAVmoG.png">

# Disclaimer
This or previous program is for Educational purpose ONLY. Do not use it without permission. The usual disclaimer applies, especially the fact that I'm not liable for any damages caused by direct or indirect use of the information or functionality provided by these programs. The author or any Internet provider bears NO responsibility for content or misuse of these programs or any derivatives thereof. By using these programs you accept the fact that any damage (dataloss, system crash, system compromise, etc.) caused by the use of these programs is not my responsibility.

# Hack and have fun !
If you have any further questions, please don't hesitate to contact me via my <a href="https://twitter.com/MoeinFatehi">twitter</a> account.
