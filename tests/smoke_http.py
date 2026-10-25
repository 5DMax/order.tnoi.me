#!/usr/bin/env python3
"""HTTP tests use a disposable PHP server; do not access any database."""
import os,subprocess,time,urllib.request,urllib.error,http.cookiejar,socket,base64,json
from pathlib import Path
root=Path(__file__).resolve().parent.parent
php=os.environ.get('PHP_BINARY','php')
sock=socket.socket();sock.bind(('127.0.0.1',0));port=sock.getsockname()[1];sock.close()
env=os.environ.copy();env['APP_ENV']='testing';env['APP_KEY']='test-key-not-a-production-secret-0000000000'
log=open('/tmp/sport-shirts-http-test.log','w')
server=subprocess.Popen([php,'-S',f'127.0.0.1:{port}','-t',str(root/'public'),str(root/'tests/router.php')],cwd=root,env=env,stdout=log,stderr=log)
base=f'http://127.0.0.1:{port}'
opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
n=0
def check(condition,label):
 global n
 assert condition,label
 n+=1;print('PASS',label)
def request(path,data=None,headers=None):
 try:return opener.open(urllib.request.Request(base+path,data=data,headers=headers or {}),timeout=4)
 except urllib.error.HTTPError as e:return e
try:
 for i in range(40):
  try:
   response=request('/');break
  except urllib.error.URLError:time.sleep(.1)
 else:raise RuntimeError('Server failed to start')
 check(response.status==200,'shop HTTP 200')
 body=response.read().decode();check('STEP 01' in body and 'STEP 04' in body,'order steps rendered')
 check("frame-ancestors 'none'" in response.headers['Content-Security-Policy'],'CSP present')
 check(response.headers['X-Content-Type-Options']=='nosniff','nosniff header')
 cookie=response.headers.get('Set-Cookie','');check('HttpOnly' in cookie and 'SameSite=Lax' in cookie,'session cookie protection')
 check(request('/privacy').status==200,'privacy page reachable')
 check(request('/admin').status==200,'admin login reachable')
 check(request('/api/orders',b'{}',{'Content-Type':'application/json'}).status==403,'CSRF blocks checkout before database')
 check(request('/api/login',b'{}',{'Content-Type':'application/json'}).status==403,'CSRF blocks login')
 check(request('/api/admin/users').status==403,'anonymous admin API blocked')
 check(request('/admin/slip/1').status==403,'anonymous slip access blocked')
 check(request('/.env').status==404,'environment file not public')
 check(request('/storage/uploads/test.php').status==404,'upload path not public')
 check(request('/assets/vendor/bootstrap.min.css').status==200,'local Bootstrap asset served')
 check(request('/assets/vendor/select2.min.js').status==200,'local Select2 asset served')
 def upload(content,filename='upload.png'):
  boundary='test-boundary-123456'
  body=(f'--{boundary}\r\nContent-Disposition: form-data; name="image"; filename="{filename}"\r\nContent-Type: image/png\r\n\r\n'.encode()+content+f'\r\n--{boundary}--\r\n'.encode())
  return request('/__test/upload',body,{'Content-Type':f'multipart/form-data; boundary={boundary}'})
 png=base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=')
 # Generate a valid PNG using the runtime image encoder, independent of client file names.
 png=subprocess.check_output([php,'-r','$im=imagecreatetruecolor(2,2);imagepng($im);'])
 result=upload(png,'../../payload.php');check(result.status==200,'valid image accepted despite hostile original filename')
 saved=json.loads(result.read());check(saved['mime']=='image/jpeg' and len(saved['filename'])==52,'image reencoded with random safe filename')
 check(upload(b'<?php echo "unsafe"; ?>').status==422,'fake image rejected by content')
 check(upload(b'x'*4194305).status==422,'upload over 4 MB rejected')
 check(upload(png[:20]).status==422,'truncated image rejected')
 boundary='batch-boundary-456'
 body=b''
 for key,content in [('image_1_1',png),('image_5_3',b'not a real image')]:
  body+=(f'--{boundary}\r\nContent-Disposition: form-data; name="{key}"; filename="test.png"\r\nContent-Type: image/png\r\n\r\n'.encode()+content+b'\r\n')
 body+=f'--{boundary}--\r\n'.encode()
 batch=request('/__test/upload-batch',body,{'Content-Type':f'multipart/form-data; boundary={boundary}'})
 check(batch.status==422,'invalid second image rejects batch')
 check(json.loads(batch.read())['cleaned'],'failed batch removes previously staged image')
 print(n,'HTTP assertions passed')
finally:
 server.terminate();server.wait(timeout=5);log.close()
