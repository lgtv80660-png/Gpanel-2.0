G-PANEL V7
==========

Stalker/MAG diagnostic workflow:
- test_stalker.php tests Host + MAC from the server before import.
- It tries common Stalker endpoints and handshake prehash variants.
- It displays HTTP status, endpoint, token detection and a sanitized response preview.
- The test does not import, delete or modify provider data.

Recommended test:
Host: http://tv4u1.com:8080/c/
MAC: 00:1A:79:14:E6:B9

If the test succeeds, use the same Host + MAC for the provider import.
