#!/bin/sh

set -eu

package_dir=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
test_root=$(mktemp -d "${TMPDIR:-/tmp}/opsfour-s3-soak-status-test.XXXXXX")
test_root=$(CDPATH='' cd -- "$test_root" && pwd -P)
mock_bin="$test_root/bin"
mkdir "$mock_bin"
trap 'rm -rf -- "$test_root"' EXIT HUP INT TERM

cat > "$mock_bin/git" <<'EOF'
#!/bin/sh
case " $* " in
    *' status --porcelain --untracked-files=normal '*) exit 0 ;;
    *' rev-parse HEAD '*) printf '0123456789abcdef0123456789abcdef01234567\n' ;;
    *) exit 2 ;;
esac
EOF

cat > "$mock_bin/docker" <<'EOF'
#!/bin/sh
set -eu

if [ "${1:-}" = compose ]; then
    case " $* " in
        *' ps --all --quiet soak '*) printf 'mock-soak-container\n' ;;
    esac
    exit 0
fi
if [ "${1:-}" = inspect ] && [ "${2:-}" = --format ]; then
    case "${3:-}" in
        'container={{.State.Status}} exit={{.State.ExitCode}} oom={{.State.OOMKilled}} started={{.State.StartedAt}} finished={{.State.FinishedAt}}')
            printf 'container=running exit=0 oom=false started=now finished=never\n'
            ;;
        '{{.State.Running}}') printf 'true\n' ;;
        '{{.State.ExitCode}}') printf '0\n' ;;
        '{{.State.OOMKilled}}') printf 'false\n' ;;
        *) exit 2 ;;
    esac
    exit 0
fi
exit 2
EOF

chmod +x "$mock_bin/git" "$mock_bin/docker"

run_case()
{
    case_name=$1
    metadata_driver=$2
    backend_status=$3
    expected_status=$4
    state_dir="$test_root/$case_name"
    mkdir "$state_dir"
    cat > "$state_dir/runtime.env" <<EOF
S3_SOAK_RUN_ID=0123456-test
S3_SOAK_DURATION_SECONDS=43200
S3_SOAK_QUALIFICATION=release
S3_SOAK_COMMIT=0123456789abcdef0123456789abcdef01234567
S3_SOAK_STARTED_AT=2026-01-01T00:00:00Z
S3_SOAK_STARTED_EPOCH=1767225600
S3_SOAK_METADATA_DRIVER=$metadata_driver
S3_SOAK_IMAGE=opsfour-s3-production-soak:test
S3_SOAK_DATA_DIR=$state_dir/data/0123456-test
S3_SOAK_HOST_UID=1000
S3_SOAK_HOST_GID=1000
EOF
    printf '%s\n' "$backend_status" > "$state_dir/$metadata_driver-container-status"

    if PATH="$mock_bin:$PATH" S3_SOAK_STATE_DIR="$state_dir" \
        "$package_dir/scripts/run-production-soak" status >/dev/null 2>&1; then
        actual_status=success
    else
        actual_status=failure
    fi
    [ "$actual_status" = "$expected_status" ]
}

for metadata_driver in postgres mysql; do
    run_case "$metadata_driver-healthy" "$metadata_driver" \
        'container=running exit=0 oom=false health=healthy started=now finished=never' \
        success
    run_case "$metadata_driver-stopped" "$metadata_driver" \
        'container=exited exit=0 oom=false health=healthy started=before finished=now' \
        failure
    run_case "$metadata_driver-oom" "$metadata_driver" \
        'container=running exit=0 oom=true health=healthy started=now finished=never' \
        failure
    run_case "$metadata_driver-unhealthy" "$metadata_driver" \
        'container=running exit=0 oom=false health=unhealthy started=now finished=never' \
        failure
done
