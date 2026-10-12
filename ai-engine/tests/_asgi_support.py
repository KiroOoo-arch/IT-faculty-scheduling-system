"""
Shared ASGI and scripted-database support for the engine's API tests.

`perform_asgi_request` drives the real ASGI application directly rather than using
`starlette.testclient.TestClient`, which requires httpx — deliberately not a
dependency of this engine. Invoking the ASGI callable still exercises routing, the
middleware stack, FastAPI's exception handlers and JSON serialization; the only
part missing is the HTTP transport, which is the one thing no server is running
here to provide.

`ScriptedCursor` answers queries from a list of (SQL fragment, rows) pairs, so a
test can exercise a database-shaped code path without a database. A response may
also be an exception instance, which is raised when that query runs — that is how
the database-failure paths are covered without a real connection.

This module is not discovered as a test file (the runner's pattern is `test*.py`).
It is imported by the test modules, which put their own directory on `sys.path`.
"""

import asyncio
import json


class AsgiResponse:
    """An ASGI response as a client sees it: status, headers and body."""

    def __init__(self, status_code, headers, body):
        self.status_code = status_code
        self.headers = headers
        self.body = body

    @property
    def text(self):
        return self.body.decode("utf-8")

    def json(self):
        return json.loads(self.body)


def perform_asgi_request(asgi_app, path, method="POST"):
    """
    Invoke an ASGI application for one request and return its response.

    The request carries no body: every endpoint under test either is a GET or takes
    its input from the path.
    """
    scope = {
        "type": "http",
        "asgi": {"version": "3.0", "spec_version": "2.3"},
        "http_version": "1.1",
        "method": method,
        "scheme": "http",
        "path": path,
        "raw_path": path.encode("utf-8"),
        "query_string": b"",
        "root_path": "",
        "headers": [(b"host", b"testserver")],
        "client": ("127.0.0.1", 12345),
        "server": ("testserver", 80),
    }

    sent = []

    async def receive():
        return {"type": "http.request", "body": b"", "more_body": False}

    async def send(message):
        sent.append(message)

    asyncio.run(asgi_app(scope, receive, send))

    status = None
    headers = {}
    body = b""

    for message in sent:
        if message["type"] == "http.response.start":
            status = message["status"]
            headers = {
                key.decode("latin-1"): value.decode("latin-1")
                for key, value in message.get("headers", [])
            }
        elif message["type"] == "http.response.body":
            body += message.get("body", b"")

    return AsgiResponse(status, headers, body)


class ScriptedCursor:
    """
    Answers queries from a script matched on SQL text.

    Anything unscripted returns no rows, so each test carries only the fixtures it
    needs and an unexpected query shows up as empty rather than as a silent hit on
    another fixture. A scripted entry whose value is an exception is raised when
    that query runs.
    """

    def __init__(self, responses):
        self._responses = responses
        self._rows = []

    def execute(self, sql, params=None):
        for fragment, rows in self._responses:
            if fragment in sql:
                if isinstance(rows, BaseException):
                    raise rows
                self._rows = rows
                return
        self._rows = []

    def fetchall(self):
        return list(self._rows)

    def fetchone(self):
        return self._rows[0] if self._rows else None

    def close(self):
        pass


class ScriptedConnection:
    def __init__(self, cursor):
        self._cursor = cursor

    def cursor(self):
        return self._cursor

    def close(self):
        pass
