#!/usr/bin/env bash
# Phase P10 — add the missing HasFactory FQCN import to models that use the trait.
set -euo pipefail
cd "$(dirname "$0")/.."

MODELS=(
  IadlRecord TbScreening AnticoagulationPlan InrResult AdverseDrugEvent
  BereavementContact DischargeEvent CareGap GoalsOfCareConversation
  PredictiveRiskScore DietaryOrder ActivityEvent StaffTask SavedDashboard
)

for m in "${MODELS[@]}"; do
  f="app/Models/${m}.php"
  [[ ! -f "$f" ]] && continue
  python3 - "$f" <<'PY'
import sys, re
path = sys.argv[1]
src = open(path).read()
if 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory' in src:
    sys.exit(0)
src = re.sub(
    r"(use Illuminate\\Database\\Eloquent\\Model;)",
    r"\1\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;",
    src,
    count=1,
)
open(path, 'w').write(src)
PY
done
echo "done"
