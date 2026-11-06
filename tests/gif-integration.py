"""Exercise PHP task creation, polling and GIF proxy against a local mock upstream.

Requires PHP CLI with cURL. All files, tasks and processes are isolated in a
temporary directory; this never calls the configured production image API.
"""
import base64
import json
import shutil
import socket
import subprocess
import tempfile
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

ROOT = Path(__file__).resolve().parent.parent
GIF = bytes.fromhex('47494638396101000100800000ff00000000ff21ff0b4e45545343415045322e30030100000021f904000a0000002c000000000100010000020244010021f904000a0000002c00000000010001000002024c01003b')
CALLS = []


class Upstream(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def do_POST(self):
        raw = self.rfile.read(int(self.headers['Content-Length']))
        body = json.loads(raw) if 'application/json' in self.headers.get('Content-Type', '') else raw.decode(errors='replace')
        CALLS.append((self.path, body))
        assert self.headers['Authorization'] == 'Bearer local-integration-test-key'
        result = json.dumps({'data': [{'url': f'http://127.0.0.1:{self.server.server_port}/result.gif'}]}).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(result)

    def do_GET(self):
        self.send_response(200)
        self.send_header('Content-Type', 'application/octet-stream')
        self.end_headers()
        self.wfile.write(GIF)


def request(base, endpoint, payload=None, headers=None):
    data = json.dumps(payload).encode() if payload is not None else None
    req = Request(base + endpoint, data=data, headers={'Content-Type': 'application/json', **(headers or {})})
    try:
        with urlopen(req, timeout=10) as res:
            return res.status, res.headers, res.read()
    except HTTPError as error:
        return error.code, error.headers, error.read()


def main():
    upstream = ThreadingHTTPServer(('127.0.0.1', 0), Upstream)
    threading.Thread(target=upstream.serve_forever, daemon=True).start()
    try:
        with tempfile.TemporaryDirectory(prefix='image-gif-e2e-') as directory:
            site = Path(directory)
            shutil.copytree(ROOT / 'api', site / 'api')
            lib = site / 'api/lib.php'
            source = lib.read_text()
            source = source.replace("const API_BASE_URL = 'https://api.kkone.vip';", f"const API_BASE_URL = 'http://127.0.0.1:{upstream.server_port}';")
            assert "const API_BASE_URL = 'http://127.0.0.1:" in source
            lib.write_text(source)
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            base = f'http://127.0.0.1:{port}'
            with (site / 'php.log').open('w') as log:
                process = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', directory], stdout=log, stderr=log)
                try:
                    for _ in range(40):
                        try:
                            request(base, '/api/config.php')
                            break
                        except URLError:
                            time.sleep(0.1)
                    models = ['gpt-image-2.6-flare', 'gpt-image-2.6-sunburst']
                    payload = {'model': models[0], 'prompt': 'A cat waving, seamless loop.', 'output_format': 'gif', 'apiKey': 'local-integration-test-key'}
                    invalid = [{'model': 'gpt-image-2'}, {'frame_count': 17}, {'frame_duration_ms': 19}, {'size': '513x512'}, {'n': 11}, {'stream': False}, {'partial_images': 1}, {'mask': 'x'}, {'output_compression': 50}, {'prompt_rewriter': False}, {'referenceImages': ['data:image/png;base64,AA==']}]
                    for change in invalid:
                        status, _, body = request(base, '/api/create-task.php', {**payload, **change})
                        assert status == 400 and json.loads(body)['error'], (change, status, body)
                    assert CALLS == [], 'invalid requests reached upstream'
                    assert not list((site / 'storage/tasks').glob('*.json')), 'invalid requests created tasks'
                    for model in models:
                        status, _, body = request(base, '/api/create-task.php', {**payload, 'model': model, 'n': 2, 'frame_duration_ms': 205})
                        assert status == 202, body
                        task_id = json.loads(body)['taskId']
                        status, _, body = request(base, '/api/check-task.php', {'taskId': task_id, 'apiKey': payload['apiKey']})
                        task = json.loads(body)
                        assert status == 200 and task['status'] == 'succeeded' and len(task['images']) == 2, task
                        assert task['request']['frame_duration_ms'] == 210 and task['request']['count'] == 2
                        for image in task['images']:
                            status, headers, content = request(base, image['url'], headers={'X-API-Key': payload['apiKey']})
                            assert status == 200 and headers['Content-Type'] == 'image/gif' and content == GIF
                    assert len(CALLS) == 4
                    for endpoint, body in CALLS:
                        assert endpoint == '/v1/images/generations'
                        assert body['n'] == 1 and body['output_format'] == 'gif' and body['size'] == '512x512'
                        assert body['frame_count'] == 9 and body['frame_duration_ms'] == 210
                        assert set(body) == {'model', 'prompt', 'n', 'response_format', 'output_format', 'frame_count', 'frame_duration_ms', 'size'}
                    reference = 'data:image/png;base64,' + base64.b64encode(GIF).decode()
                    for model in models:
                        for refs in [[], [reference]]:
                            status, _, body = request(base, '/api/create-task.php', {'model': model, 'prompt': 'Static image.', 'ratio': '1:1', 'exactSize': '1024x1024', 'quality': 'high', 'referenceImages': refs, 'apiKey': payload['apiKey']})
                            assert status == 202, body
                            task_id = json.loads(body)['taskId']
                            status, _, body = request(base, '/api/check-task.php', {'taskId': task_id, 'apiKey': payload['apiKey']})
                            assert json.loads(body)['status'] == 'succeeded', body
                            endpoint, body = CALLS[-1]
                            assert endpoint == ('/v1/images/edits' if refs else '/v1/images/generations')
                            if not refs:
                                assert body['model'] == model and body['quality'] == 'high' and 'output_format' not in body
                            else:
                                assert model in body and 'name="image"' in body
                finally:
                    process.terminate()
                    process.wait(timeout=5)
            assert 'Fatal error' not in (site / 'php.log').read_text()
        print('PASS: 11 invalid API requests rejected before queue/upstream, both GIF models, sequential batch, polling, animated byte proxy and static generation/edit integration (local mock).')
    finally:
        upstream.shutdown()
        upstream.server_close()


if __name__ == '__main__':
    main()
