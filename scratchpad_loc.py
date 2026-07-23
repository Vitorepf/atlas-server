import re
f='app/Services/Ai/RealExecution/AtlasRealEngineeringExecutionKernelService.php'
lines=open(f).read().split('\n')
start=next(i for i,l in enumerate(lines) if l.startswith('class AtlasRealEngineeringExecutionKernelService'))
methre=re.compile(r'^\s+(public|private|protected)(\s+static)?\s+function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(')
# find method start lines (with preceding docblock)
meths=[]
for i in range(start,len(lines)):
    m=methre.match(lines[i])
    if m:
        meths.append((i,m.group(1)+(m.group(2) or ''),m.group(3)))
# span = from this method (incl leading docblock) to next method start
for idx,(ln,vis,name) in enumerate(meths):
    end=meths[idx+1][0] if idx+1<len(meths) else len(lines)-1
    # back up over docblock/comment/attribute lines
    s=ln
    j=ln-1
    while j>start and (lines[j].strip().startswith('*') or lines[j].strip().startswith('/**') or lines[j].strip().startswith('//') or lines[j].strip().startswith('#[') or lines[j].strip()==''):
        # only pull contiguous docblock immediately above
        if lines[j].strip()=='' : break
        s=j; j-=1
    loc=end-s
    print(f"{loc:4d}  {vis:10s} {name}")
