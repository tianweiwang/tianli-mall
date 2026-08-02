## 1. Deployment artifacts

- [x] 1.1 Add a prebuilt-image Compose file with one public port and persistent bind mounts.
- [x] 1.2 Add a placeholder-only environment template and demonstration deployment notes.
- [x] 1.3 Remove unnecessary remote Dockerfile frontend declarations and verify local offline-cache builds.

## 2. Release preparation

- [x] 2.1 Rebuild and verify the PHP 8.2 application images.
- [x] 2.2 Package the application images, authoritative sample SQL, uploads, and server configuration without local credentials.
- [x] 2.3 Install Docker and host swap on the designated Aliyun demonstration server.

## 3. Remote deployment

- [x] 3.1 Load the application images and restore the sample uploads into `/srv/tianli/crmeb-demo`.
- [x] 3.2 Start the Compose project and wait for all four services to become healthy.
- [ ] 3.3 Verify PHP, processes, HTTP, WebSocket, business JSON, database snapshot, uploads, and the existing H5 Demo.

## 4. Closeout

- [x] 4.1 Run focused local checks and `openspec validate deploy-crmeb-aliyun-demo`.
- [ ] 4.2 Record the deployment commit, access URL, rollback command, and any Aliyun security-group requirement.
