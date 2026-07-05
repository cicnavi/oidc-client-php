# OpenID Connect Relying Party Conformance Testing

The `oidc-client-php` library has been fully tested and verified against the official **OpenID Connect Relying Party (RP) Conformance Suite**.

Specifically, currently we run the following OpenID Conformance Tests:
* **Basic RP profile** (`oidcc-client-basic-certification-test-plan` plan using static client registration and plain HTTP request authorization).
* **Basic RP profile with dynamic client registration** (`oidcc-client-basic-certification-test-plan` plan using dynamic client registration and plain HTTP request authorization).
* **RP-Initiated Logout RP profile (Basic)** (`oidcc-client-rp-initiated-logout-rp-basic` plan using static client registration and plain HTTP request authorization).
* **RP-Initiated Logout RP profile (Basic) with dynamic client registration** (`oidcc-client-rp-initiated-logout-rp-basic` plan using dynamic client registration and plain HTTP request authorization).
* **Back-Channel Logout RP profile (Basic)** (`oidcc-client-back-channel-logout-rp-basic` plan using static client registration and plain HTTP request authorization).
* **Back-Channel Logout RP profile (Basic) with dynamic client registration** (`oidcc-client-back-channel-logout-rp-basic` plan using dynamic client registration and plain HTTP request authorization).

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

The RP test application uses static client registration (`PreRegisteredClient` with the
`CLIENT_ID` / `CLIENT_SECRET` values from `docker/docker-compose.yml`) by default. To run it
with dynamic client registration instead (`DynamicallyRegisteredClient`, which registers itself
on the OP's registration endpoint), set the `CLIENT_REGISTRATION` environment variable when
starting the container:
   ```bash
   CLIENT_REGISTRATION=dynamic_client docker compose -f docker/docker-compose.yml up --build -d
   ```
Since every conformance test module is a fresh OP instance served on the same issuer URL, the
RP test application performs a new client registration each time an authorization flow is
started (any previously persisted registration would be stale).

For the RP-Initiated Logout test plan, additionally set `LOGOUT_FLOW=rp_initiated`. The RP test
application then continues every completed login with an RP-Initiated Logout: the callback
redirects to `/logout` (which sends the logout request to the OP's `end_session_endpoint` with
`id_token_hint`, `client_id`, `post_logout_redirect_uri` and `state`), and the OP redirects back
to `/logout-callback`, where the state parameter is validated (an invalid or missing state is
rejected, as exercised by the negative test modules).

Note that the RP-Initiated Logout test modules require the client to also have a
`backchannel_logout_uri` or `frontchannel_logout_uri` registered (condition
`EnsureClientHasAtLeastOneOfBackOrFrontChannelLogoutUri`), and the suite sends a Back-Channel
Logout request to it while handling the `end_session_endpoint` request. The RP test application
therefore exposes a `/backchannel-logout` endpoint, which uses the library's
`handleBackchannelLogoutRequest()` to validate the logout token, record the login revocation,
and respond (200 when the logout was performed, 400 when the logout token was rejected - as
exercised by the negative Back-Channel Logout test modules). It is registered statically via
`backchannel_logout_uri` in the conformance test configuration JSON files, and dynamically via
the `DynamicallyRegisteredClient` `backchannelLogoutUri` constructor parameter:
   ```bash
   LOGOUT_FLOW=rp_initiated docker compose -f docker/docker-compose.yml up --build -d
   # ... or, with dynamic client registration (post_logout_redirect_uris is then
   # registered on the OP's registration endpoint):
   CLIENT_REGISTRATION=dynamic_client LOGOUT_FLOW=rp_initiated docker compose -f docker/docker-compose.yml up --build -d
   ```

The Back-Channel Logout test plan uses the same RP flow as the RP-Initiated Logout plan (the
suite delivers the logout token while handling the `end_session_endpoint` request), so the RP
test application is started the same way (`LOGOUT_FLOW=rp_initiated`) for both plans.

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
3. For the dynamic client registration variant (with the RP test application started using
   `CLIENT_REGISTRATION=dynamic_client`, see Step 2), run the plan with the `dynamic_client`
   variant and the dynamic configuration file (which contains no static client credentials):
   ```bash
   python3 /path/to/conformance-suite/scripts/run-test-plan.py \
     --expected-failures-file conformance-tests/basic-warnings.json \
     --expected-skips-file conformance-tests/basic-skips.json \
     "oidcc-client-basic-certification-test-plan[client_registration=dynamic_client][request_type=plain_http_request]" \
     conformance-tests/conformance-basic-dynamic-ci.json
   ```
4. For the RP-Initiated Logout plan (with the RP test application started using
   `LOGOUT_FLOW=rp_initiated`, see Step 2), run (without the `--expected-skips-file` option,
   since the skipped test module only exists in the Basic plan):
   ```bash
   python3 /path/to/conformance-suite/scripts/run-test-plan.py \
     --expected-failures-file conformance-tests/basic-warnings.json \
     "oidcc-client-rp-initiated-logout-rp-basic[client_auth_type=client_secret_basic][client_registration=static_client][request_type=plain_http_request]" \
     conformance-tests/conformance-rp-logout-ci.json
   ```
   or, for the dynamic client registration variant (RP test application started using
   `CLIENT_REGISTRATION=dynamic_client LOGOUT_FLOW=rp_initiated`):
   ```bash
   python3 /path/to/conformance-suite/scripts/run-test-plan.py \
     --expected-failures-file conformance-tests/basic-warnings.json \
     "oidcc-client-rp-initiated-logout-rp-basic[client_auth_type=client_secret_basic][client_registration=dynamic_client][request_type=plain_http_request]" \
     conformance-tests/conformance-rp-logout-dynamic-ci.json
   ```
5. For the Back-Channel Logout plan (with the RP test application started using
   `LOGOUT_FLOW=rp_initiated`, same as for the RP-Initiated Logout plan), run:
   ```bash
   python3 /path/to/conformance-suite/scripts/run-test-plan.py \
     --expected-failures-file conformance-tests/basic-warnings.json \
     "oidcc-client-back-channel-logout-rp-basic[client_auth_type=client_secret_basic][client_registration=static_client][request_type=plain_http_request]" \
     conformance-tests/conformance-backchannel-logout-ci.json
   ```
   or, for the dynamic client registration variant (RP test application started using
   `CLIENT_REGISTRATION=dynamic_client LOGOUT_FLOW=rp_initiated`):
   ```bash
   python3 /path/to/conformance-suite/scripts/run-test-plan.py \
     --expected-failures-file conformance-tests/basic-warnings.json \
     "oidcc-client-back-channel-logout-rp-basic[client_auth_type=client_secret_basic][client_registration=dynamic_client][request_type=plain_http_request]" \
     conformance-tests/conformance-backchannel-logout-dynamic-ci.json
   ```

All test modules should complete and pass cleanly.
