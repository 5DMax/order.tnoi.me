#!/usr/bin/env python3
"""Download pinned distributables locally. No runtime CDN or Node dependency."""
from pathlib import Path
from urllib.request import urlopen
import hashlib,json
base=Path(__file__).resolve().parent.parent/'public/assets/vendor'
assets={
 'bootstrap.LICENSE.txt':'bootstrap@5.3.3/LICENSE',
 'jquery.LICENSE.txt':'jquery@3.7.1/LICENSE.txt',
 'select2.LICENSE.txt':'select2@4.0.13/LICENSE.md',
 'select2-theme.LICENSE.txt':'select2-bootstrap-5-theme@1.3.0/LICENSE',
 'sweetalert2.LICENSE.txt':'sweetalert2@11.14.5/LICENSE',
 'bootstrap.min.css':'bootstrap@5.3.3/dist/css/bootstrap.min.css',
 'bootstrap.bundle.min.js':'bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
 'jquery.min.js':'jquery@3.7.1/dist/jquery.min.js',
 'select2.min.css':'select2@4.0.13/dist/css/select2.min.css',
 'select2.min.js':'select2@4.0.13/dist/js/select2.min.js',
 'select2-bootstrap-5-theme.min.css':'select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css',
 'sweetalert2.min.js':'sweetalert2@11.14.5/dist/sweetalert2.min.js',
 'sweetalert2.min.css':'sweetalert2@11.14.5/dist/sweetalert2.min.css',
}
manifest={}
for name,path in assets.items():
 data=urlopen('https://cdn.jsdelivr.net/npm/'+path,timeout=30).read()
 (base/name).write_bytes(data)
 manifest[name]={'package':path,'sha256':hashlib.sha256(data).hexdigest()}
(base/'manifest.json').write_text(json.dumps(manifest,indent=2)+'\n')
print('Downloaded',len(assets),'pinned local assets')
