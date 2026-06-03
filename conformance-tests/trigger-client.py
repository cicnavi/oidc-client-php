import httpx
import time
import sys
import urllib3
import urllib.parse

# Disable SSL warnings since we are using self-signed certs
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

print("Starting trigger-client.py daemon...", flush=True)

triggered = set()

# Configure httpx clients
conformance_client = httpx.Client(verify=False)
rp_client = httpx.Client(verify=False, follow_redirects=False)

conformance_url = "https://localhost.emobix.co.uk:8443"

def trigger_rp():
    url = "https://127.0.0.1/"
    headers = {"Host": "rp.local.conformance.test"}
    cookies = {}

    # Follow up to 10 redirects manually
    for redirect_num in range(10):
        print(f"  [Redirect {redirect_num}] Requesting: {url} (Host: {headers.get('Host')})", flush=True)
        try:
            resp = rp_client.get(url, headers=headers, cookies=cookies)
        except Exception as e:
            print(f"  Request failed: {e}", flush=True)
            return None

        # Update cookies
        for name, value in resp.cookies.items():
            cookies[name] = value

        if resp.status_code in (301, 302, 303, 307, 308):
            redirect_url = resp.headers["Location"]
            parsed = urllib.parse.urlparse(redirect_url)
            
            if parsed.netloc == "rp.local.conformance.test":
                # Rewrite to 127.0.0.1 and keep Host header
                url = urllib.parse.urlunparse(parsed._replace(netloc="127.0.0.1"))
                headers["Host"] = "rp.local.conformance.test"
            elif parsed.netloc == "localhost.emobix.co.uk:8443":
                # Keep as is, but remove Host header
                url = redirect_url
                if "Host" in headers:
                    del headers["Host"]
            else:
                url = redirect_url
                if "Host" in headers:
                    del headers["Host"]

            continue
        
        # Non-redirect response, we are done
        return resp
    
    print("  Too many redirects", flush=True)
    return None

# We will run this loop until we get a signal to stop or for a max timeout
start_time = time.time()
timeout = 1800 # 30 minutes max

while time.time() - start_time < timeout:
    try:
        # Get list of running test modules
        response = conformance_client.get(f"{conformance_url}/api/runner/running")
        if response.status_code != 200:
            time.sleep(2)
            continue

        running_test_ids = response.json()
        for test_id in running_test_ids:
            if test_id in triggered:
                continue

            # Check status of this test
            info_response = conformance_client.get(f"{conformance_url}/api/info/{test_id}")
            if info_response.status_code != 200:
                continue

            info = info_response.json()
            status = info.get("status")
            test_name = info.get("testName")

            if status == "WAITING":
                print(f"Test {test_id} ({test_name}) is WAITING. Triggering RP client...", flush=True)
                
                trigger_resp = trigger_rp()
                if trigger_resp is not None:
                    print(f"Trigger request completed. Status: {trigger_resp.status_code}", flush=True)
                    if "submission_complete" in trigger_resp.text:
                        print("SUCCESS: submission_complete found in response!", flush=True)
                    else:
                        print("WARNING: submission_complete NOT found in response!", flush=True)
                        print(trigger_resp.text[:1000], flush=True) # Print first 1000 chars of response for debug
                else:
                    print("ERROR: Trigger request failed to return a response.", flush=True)

                triggered.add(test_id)
    except Exception as e:
        print(f"Error in polling loop: {e}", flush=True)

    time.sleep(1)

print("trigger-client.py daemon stopping.", flush=True)
