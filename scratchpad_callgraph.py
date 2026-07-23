import re
f='app/Services/Ai/RealExecution/AtlasRealEngineeringExecutionKernelService.php'
src=open(f).read()
lines=src.split('\n')
# find main class start (line 83, index 82)
start=None
for i,l in enumerate(lines):
    if l.startswith('class AtlasRealEngineeringExecutionKernelService'):
        start=i; break
body='\n'.join(lines[start:])
# find method defs with visibility
methre=re.compile(r'^\s+(public|private|protected)(?:\s+static)?\s+function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(', re.M)
methods=[]
for m in methre.finditer(body):
    methods.append((m.group(2), m.group(1), m.start()))
names=set(n for n,_,_ in methods)
# for each method, find span (until next method start) and calls to $this->X( within
calls={}
vis={}
for idx,(name,v,pos) in enumerate(methods):
    end=methods[idx+1][2] if idx+1<len(methods) else len(body)
    seg=body[pos:end]
    called=set(re.findall(r'\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(', seg))
    called={c for c in called if c in names and c!=name}
    calls[name]=called
    vis[name]=v
# print
pub=[n for n,_,_ in methods if vis[n]=='public']
priv=[n for n,_,_ in methods if vis[n] in ('private','protected')]
print("PUBLIC (%d):"%len(pub))
for n in pub: print("  ",n,"->",sorted(calls[n]))
print("\nPRIVATE/PROTECTED (%d):"%len(priv))
for n in priv: print("  [%s] %s -> %s"%(vis[n],n,sorted(calls[n])))
# who calls each private?
callers={}
for n in names:
    callers[n]=sorted([m for m in names if n in calls[m]])
print("\nCALLERS of each private/protected:")
for n in priv:
    print("  ",n,"<-",callers[n])
