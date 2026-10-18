#!/bin/sh

set -eu

package_dir=$(CDPATH='' cd -- "$(dirname -- "$0")/.." && pwd)
test_root=$(mktemp -d "${TMPDIR:-/tmp}/opsfour-s3-soak-image-test.XXXXXX")
test_root=$(CDPATH='' cd -- "$test_root" && pwd -P)
mock_bin="$test_root/bin"
mkdir "$mock_bin"
trap 'rm -rf -- "$test_root"' EXIT HUP INT TERM

cat > "$mock_bin/uname" <<'EOF'
#!/bin/sh
printf 'Linux\n'
EOF

cat > "$mock_bin/id" <<'EOF'
#!/bin/sh
case "${1:-}" in
    -u) printf '1000\n' ;;
    -g) printf '1000\n' ;;
    *) exit 2 ;;
esac
EOF

cat > "$mock_bin/git" <<'EOF'
#!/bin/sh
case " $* " in
    *' status --porcelain --untracked-files=normal '*) exit 0 ;;
    *' rev-parse --short HEAD '*) printf '0123456\n' ;;
    *' rev-parse HEAD '*) printf '0123456789abcdef0123456789abcdef01234567\n' ;;
    *) exit 2 ;;
esac
EOF

cat > "$mock_bin/sleep" <<'EOF'
#!/bin/sh
exit 0
EOF

cat > "$mock_bin/docker" <<'EOF'
#!/bin/sh
set -eu
printf 'image=%s command=%s\n' "${S3_SOAK_IMAGE:-}" "$*" >> "$MOCK_DOCKER_LOG"

if [ "${1:-}" = image ] && [ "${2:-}" = inspect ]; then
    [ "$MOCK_IMAGE_PRESENT" = 1 ]
    exit
fi
if [ "${1:-}" = compose ]; then
    case " $* " in
        *' ps --all --quiet soak '*) printf 'mock-soak-container\n' ;;
    esac
    exit 0
fi
if [ "${1:-}" = inspect ]; then
    printf 'true\n'
    exit 0
fi
exit 0
EOF

chmod +x "$mock_bin/uname" "$mock_bin/id" "$mock_bin/git" \
    "$mock_bin/sleep" "$mock_bin/docker"

if command -v sha256sum >/dev/null 2>&1; then
    dockerfile_hash=$(sha256sum "$package_dir/docker/production-soak/Dockerfile" | awk '{print $1}')
else
    dockerfile_hash=$(shasum -a 256 "$package_dir/docker/production-soak/Dockerfile" | awk '{print $1}')
fi
expected_image="opsfour-s3-production-soak:$dockerfile_hash"

soak_service=$(sed -n '/^  soak:/,/^[^ ]/p' \
    "$package_dir/docker/production-soak/compose.yml")
if printf '%s\n' "$soak_service" | grep -Eq '^    build:'; then
    printf 'Soak service still permits an implicit image build.\n' >&2
    exit 1
fi
printf '%s\n' "$soak_service" | grep -Eq '^    pull_policy: never$'

mysql_service=$(sed -n '/^  mysql:/,/^[^ ]/p' \
    "$package_dir/docker/production-soak/compose.yml")
printf '%s\n' "$mysql_service" \
    | grep -Fq -- '--log-bin-trust-function-creators=ON'

run_case()
{
    case_name=$1
    image_present=$2
    expected_status=$3
    requested_image=${4:-}
    case_image=${5:-$expected_image}
    state_dir="$test_root/$case_name"
    log_file="$test_root/$case_name.log"
    error_file="$test_root/$case_name.err"

    if PATH="$mock_bin:$PATH" \
        MOCK_DOCKER_LOG="$log_file" \
        MOCK_IMAGE_PRESENT="$image_present" \
        S3_SOAK_DURATION_SECONDS=1 \
        S3_SOAK_IMAGE="$requested_image" \
        S3_SOAK_STATE_DIR="$state_dir" \
        "$package_dir/scripts/run-production-soak" start >/dev/null 2>"$error_file"; then
        actual_status=success
    else
        actual_status=failure
    fi
    [ "$actual_status" = "$expected_status" ]

    grep -Fqx "image=$case_image command=image inspect $case_image" "$log_file"
    actual_builds=$(awk '/ command=compose .* build soak$/ { count++ } END { print count + 0 }' "$log_file")
    [ "$actual_builds" -eq 0 ]
    if grep -Eq ' command=.*(^| )pull( |$)' "$log_file"; then
        printf 'Runner attempted to pull an image.\n' >&2
        exit 1
    fi
    if [ "$expected_status" = success ]; then
        grep -Fqx "S3_SOAK_IMAGE=$case_image" "$state_dir/runtime.env"
    else
        grep -Fq "Production-soak image $case_image is not available locally." "$error_file"
        [ ! -e "$state_dir/runtime.env" ]
    fi
}

run_case cached 1 success
run_case missing 0 failure
run_case explicit 1 success registry.example.test/opsfour/s3-soak:cached \
    registry.example.test/opsfour/s3-soak:cached

digest_log="$test_root/digest.log"
if PATH="$mock_bin:$PATH" \
    MOCK_DOCKER_LOG="$digest_log" \
    MOCK_IMAGE_PRESENT=1 \
    S3_SOAK_IMAGE='opsfour-s3-production-soak@sha256:0123456789abcdef' \
    S3_SOAK_STATE_DIR="$test_root/digest" \
    "$package_dir/scripts/run-production-soak" start >/dev/null 2>&1; then
    printf 'Digest image reference was accepted as a local build tag.\n' >&2
    exit 1
fi
[ ! -e "$digest_log" ]
