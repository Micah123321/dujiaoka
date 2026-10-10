#!/usr/bin/env python3
import gzip
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import secrets
import tarfile

spec=importlib.util.spec_from_file_location('image_unpack',Path(__file__).with_name('unpack-rootfs.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
m.require(os.getuid()==1000 and os.geteuid()==1000,'Actual Linux user1000 required')
marker=secrets.token_hex(12);base=Path('/var/tmp/dujiaoka-oci-controls50-'+marker);base.mkdir(mode=0o700)
root=base/'rootfs';root.mkdir(mode=0o700);outside=base/'outside';outside.mkdir(mode=0o700);sentinel=outside/'sentinel';sentinel.write_bytes(b'original-owned-host-sentinel')
checks=0

def check(ok,label):
    global checks
    m.require(ok,'Actual OCI control: '+label);checks+=1

def tar(entries):
    buffer=io.BytesIO()
    with tarfile.open(fileobj=buffer,mode='w',format=tarfile.PAX_FORMAT)as archive:
        for name,kind,data in entries:
            member=tarfile.TarInfo(name);member.uid=0;member.gid=0;member.mode=0o755 if kind=='dir' else 0o644
            member.type={'file':tarfile.REGTYPE,'dir':tarfile.DIRTYPE,'sym':tarfile.SYMTYPE,'hard':tarfile.LNKTYPE}[kind]
            if kind=='file':member.size=len(data);archive.addfile(member,io.BytesIO(data))
            else:member.linkname=data or '';archive.addfile(member)
    return buffer.getvalue()

layers=[tar([('lower','dir',''),('lower/obsolete','file',b'old'),('lower/keep','file',b'keep'),('opaque','dir',''),('opaque/old','file',b'old'),('real','dir',''),('real/original','file',b'original'),('sym','sym','/real'),('hard-original','file',b'hard'),('hard-copy','hard','hard-original'),('guard','sym',str(outside))]),
    tar([('opaque/new','file',b'new'),('opaque/.wh..wh..opq','file',b''),('lower/.wh.obsolete','file',b''),('lower/new','file',b'new'),('sym/new','file',b'in-image'),('guard/sentinel','file',b'new-in-image')]),
    tar([('sym','dir',''),('sym/now-directory','file',b'directory-child')])]
fds=[];tree=m.ImageTree(root)
try:
    descriptors=[];diffs=[]
    for position,data in enumerate(layers):
        blob=gzip.compress(data,mtime=0);file=base/('layer-%d.gz'%position);file.write_bytes(blob);fd=m.regular_fd(file);fds.append(fd)
        descriptors.append({'digest':m.sha256(blob),'size':len(blob)});diffs.append(m.sha256(data))
    plans,_,_,_=m.inspect_layers(fds,descriptors,diffs)
    for fd,plan in zip(fds,plans):m.apply_layer(tree,fd,plan)
    check(not(root/'lower/obsolete').exists()and(root/'lower/keep').read_bytes()==b'keep'and(root/'lower/new').read_bytes()==b'new','explicit whiteout only lower target')
    check(not(root/'opaque/old').exists()and(root/'opaque/new').read_bytes()==b'new','opaque marker after sibling preserves same-layer new content')
    check((root/'real/new').read_bytes()==b'in-image'and(root/'real/original').read_bytes()==b'original','absolute image symlink parent follows inside image')
    image_sentinel=root.joinpath(*outside.parts[1:],'sentinel')
    check(image_sentinel.read_bytes()==b'new-in-image'and sentinel.read_bytes()==b'original-owned-host-sentinel','absolute host-looking target remaps to image and leaves host sentinel')
    check((root/'sym').is_dir()and not(root/'sym').is_symlink()and(root/'sym/now-directory').read_bytes()==b'directory-child','final symlink replacement preserves its earlier target')
    check((root/'hard-original').stat().st_ino==(root/'hard-copy').stat().st_ino and(root/'hard-copy').read_bytes()==b'hard','actual hardlink inode and bytes')
    for name,kind,target in [('../outside/sentinel','file',None),('/outside/sentinel','file',None),('bad-hard','hard','../outside/sentinel')]:
        member=tarfile.TarInfo(name);member.type=tarfile.LNKTYPE if kind=='hard'else tarfile.REGTYPE;member.linkname=target or ''
        caught=False
        try:m.validate_member(member)
        except m.UnpackError:caught=True
        check(caught,'malformed entry/hardlink rejected before use')
    fd=os.open(root,m.DIR_FLAGS)
    try:os.symlink('loop','loop',dir_fd=fd)
    finally:os.close(fd)
    caught=False
    try:tree.resolve(('loop','child'))
    except m.UnpackError:caught=True
    check(caught,'real image symlink loop bounded')
    check(sentinel.read_bytes()==b'original-owned-host-sentinel','host marker bytes retained after all controls')
    result={'status':'PASS','checks':checks,'root':str(root),'marker':marker,'scope':'Three actual gzip/tar changesets applied through same diffID/whiteout/dirFD parser, lower deletion and opaque same-layer ordering, absolute image link vs real host marker, final link replacement, actual hardlink inode, rejected traversal/absolute entry and loop. No image runtime/host permission changes.'}
    (base/'summary.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result))
finally:
    tree.close()
    for fd in fds:os.close(fd)
