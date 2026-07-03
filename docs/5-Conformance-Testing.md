# OpenID Connect Relying Party Conformance Testing

The `oidc-client-php` library has been fully tested and verified against the official **OpenID Connect Relying Party (RP) Conformance Suite**.

Specifically, currently we run the following OpenID Conformance Tests:
* **Basic RP profile** (`oidcc-client-basic-certification-test-plan` plan using static client registration and plain HTTP request authorization).

---

## How to Run Conformance Tests Locally

### Prerequisites

- **Docker and Docker Compose** installed.
- **Python 3** with `httpx` package installed:
  ```bash
  pip install httpx
  ```

---

### Step 1: Set Up the Conformance Suite

1. Clone the OpenID Conformance Suite repository:
   ```bash
   git clone --depth 1 --single-branch --branch release-v5.1.40 https://gitlab.com/openid/conformance-suite.git
   ```
2. Build the conformance suite (requires Docker):
   ```bash
   cd conformance-suite
   # Set localhost mapping for internal routing
   sed -i -e 's/localhost/localhost.emobix.co.uk/g' src/main/resources/application.properties
   sed -i -e 's/-B clean/-B -DskipTests=true/g' builder-compose.yml
   docker compose -f builder-compose.yml run builder
   ```
3. Start the conformance suite:
   ```bash
   docker compose -f docker-compose-dev.yml up -d
   ```
4. Verify the suite is healthy (it should return a valid JSON list of plans/available runner endpoints):
   ```bash
   curl -sk https://localhost.emobix.co.uk:8443/api/runner/available
   ```

---

### Step 2: Start the RP Test Application

1. Go back to your local `oidc-client-php` directory.
2. Build and start the Relying Party test application Docker container:
   ```bash
   docker compose -f docker/docker-compose.yml up --build -d
   ```

---

### Step 3: Run the Conformance Tests

1. Start the OIDC trigger client daemon in the background (or in a separate terminal window):
   ```bash
   python3 conformance-tests/trigger-client.py
   ```
2. Run the test plan runner script:
   ```bash
   python3 /path/to/conformance-suite/scripts/run-test-plan.py \
     --expected-failures-file conformance-tests/basic-warnings.json \
     --expected-skips-file conformance-tests/basic-skips.json \
     "oidcc-client-basic-certification-test-plan[client_registration=static_client][request_type=plain_http_request]" \
     conformance-tests/conformance-basic-ci.json
   ```

All test modules should complete and pass cleanly.
