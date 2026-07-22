import os, subprocess, csv, re
from collections import Counter
ROOT="/Users/vitorepf/develop/Atlas/atlas-server"
BLOCK="app/Services/Ai/AutonomousEvolution"
BRAIN=BLOCK+"/Brain"
SEARCH=["app","tests","config","routes","database"]
os.chdir(ROOT)

KEEP={"AtlasLoopHarnessGuard","AtlasLoopMasterSwitch","AtlasLoopScopeComprehensionModel","AtlasLoopScopeComprehensionModelBuilder","AtlasLoopScopeComprehensionQuery","AtlasLoopRefillerPayloadNormalizer","AtlasLoopCortexRoleTokenSemanticDisambiguator","AtlasLoopComprehensionCadenceService","AtlasLoopProposalPromotionGate","AtlasLoopSiblingTestResolver","AtlasLoopGiveBackToReplenisherFeedback","AtlasLoopLossObserverService","AtlasLoopComprehensionOriginator","AtlasLoopProjectionOutcomeLedger","AtlasLoopOriginationPipeline","AtlasLoopAmbitionLeapProposer","AtlasLoopAutoArchitectureProposalService","AtlasLoopAutoMergeService","AtlasLoopComprehensionGroundingGate","AtlasLoopContractGapScanner","AtlasLoopFrontierGapModel","AtlasLoopHeavyWorkSelector","AtlasLoopLearningAppendService","AtlasLoopMergeActuator","AtlasLoopPatternLearningLedger","AtlasLoopRefillerSupplyLaneCoordinator","AtlasLoopQueueRefiller"}

# LIVE seed roots (pétreo live zone per task context). Brain handled separately.
LIVE_DIRS=[
 "app/Services/Ai/SelfConstruction/",
 "app/Services/Ai/SoftwareCompanyStewardship/",
 "app/Http/Controllers/Ai/SoftwareCompanyStewardship/",
 "app/Services/Ai/Programming/",
 "app/Http/Controllers/Ai/Programming/",
 "app/Services/Ai/Foundry/",
 "app/Console/Commands/Foundry/",
 "app/Services/Ai/EngineeringKernel/",
 "app/Models/",
 "app/Providers/AppServiceProvider.php",
]
def is_live_ref(p):
    if p.startswith(BRAIN): return True
    for d in LIVE_DIRS:
        if p.startswith(d): return True
    # live commands
    b=os.path.basename(p)
    if p.startswith("app/Console/Commands/") and re.match(r"Atlas(Brain|Task)", b): return True
    return False

files=sorted(subprocess.run(["find",BLOCK,"-name","*.php","-not","-path","*/Brain/*"],capture_output=True,text=True).stdout.split())
file_of={os.path.basename(f)[:-4]:f for f in files}
def base(f): return os.path.basename(f)[:-4]
def loc(f):
    try:
        with open(f,errors="ignore") as fh: return sum(1 for _ in fh)
    except: return 0

data={}
for c,f in file_of.items():
    r=subprocess.run(["rg","--no-ignore","-l","-w",c]+SEARCH,capture_output=True,text=True)
    rf=[x for x in r.stdout.split() if x and x!=f]
    live_ext=[x for x in rf if not x.startswith(BLOCK) and is_live_ref(x)]
    live_brain=[x for x in rf if x.startswith(BRAIN)]  # brain = live
    other_ext=[x for x in rf if not x.startswith(BLOCK) and not is_live_ref(x)]  # tests/config/misc = NOT live
    inblock=[x for x in rf if x.startswith(BLOCK) and not x.startswith(BRAIN)]
    data[c]=dict(file=f,live_ext=live_ext,live_brain=live_brain,other_ext=other_ext,inblock=inblock,allrefs=len(rf))

status={}
for c,d in data.items():
    if d["live_ext"] or d["live_brain"]:
        status[c]="VIVO-EXTERNO"
    else:
        status[c]=None
# transitive closure from live seeds through in-block edges, to fixpoint
for _ in range(10):
    changed=False
    for c,d in data.items():
        if status[c] is not None: continue
        for rf in d["inblock"]:
            rb=base(rf)
            if rb in status and status[rb] is not None:
                status[c]="VIVO-TRANSITIVO"; changed=True; break
    if not changed: break
for c in data:
    if status[c] is None: status[c]="ORFAO"

keep_orphans=[c for c in KEEP if status.get(c)=="ORFAO"]
cnt=Counter(status.values())
orphan_loc=sum(loc(data[c]["file"]) for c in data if status[c]=="ORFAO")
zero=[c for c in data if status[c]=="ORFAO" and data[c]["allrefs"]==0]

csvp="/private/tmp/claude-501/-Users-vitorepf-develop-Atlas-atlas-server/95bbb8ab-40c2-4670-9015-325130161315/scratchpad/acde_orphans.csv"
with open(csvp,"w",newline="") as fh:
    w=csv.writer(fh); w.writerow(["arquivo","classe","status","refs_externas","refs_no_bloco","loc"])
    for c in sorted(data):
        d=data[c]
        w.writerow([d["file"],c,status[c],len(d["live_ext"])+len(d["live_brain"]),len(d["inblock"]),loc(d["file"])])

print("TOTAL non-Brain:",len(data))
print("counts:",dict(cnt))
print("orphan_LOC:",orphan_loc)
print("keep-list in ORFAO (must be []):",keep_orphans)
print("zero-ref orphans:",len(zero))
print("orphans referenced only by tests/config/dead (allrefs>0):",sum(1 for c in data if status[c]=='ORFAO' and data[c]['allrefs']>0))
print("SAMPLE 10 zero-ref orphans:")
for c in sorted(zero)[:10]: print("   0refs",c,"->",data[c]["file"],f"({loc(data[c]['file'])} loc)")
print("CSV:",csvp)
