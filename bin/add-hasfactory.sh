#!/usr/bin/env bash
# Phase P10 — add HasFactory trait to Wave I-N models that lack it.
set -euo pipefail
cd "$(dirname "$0")/.."

MODELS=(
  IadlRecord TbScreening AnticoagulationPlan InrResult AdverseDrugEvent
  BereavementContact DischargeEvent CareGap GoalsOfCareConversation
  PredictiveRiskScore DietaryOrder ActivityEvent StaffTask SavedDashboard
)

for m in "${MODELS[@]}"; do
  f="app/Models/${m}.php"
  if [[ ! -f "$f" ]]; then echo "skip: $f not found"; continue; fi
  if grep -q 'HasFactory' "$f"; then echo "skip: $m already has HasFactory"; continue; fi
  # Add the trait import + use statement after `extends Model {`
  python3 - "$f" "$m" <<'PY'
import sys, re
path, name = sys.argv[1], sys.argv[2]
src = open(path).read()
# Add import if missing
if 'Illuminate\\Database\\Eloquent\\Factories\\HasFactory' not in src:
    src = re.sub(
        r"(use Illuminate\\\\Database\\\\Eloquent\\\\Model;)",
        r"\1\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;",
        src,
        count=1,
    )
# Add `use HasFactory;` after the opening class brace
src = re.sub(
    rf"(class {name} extends Model\s*\{{\n)",
    r"\1    use HasFactory;\n\n",
    src,
    count=1,
)
open(path, 'w').write(src)
print(f"updated: {name}")
PY
done
