# NOYA CI/CD Secrets (GitHub Actions)

Create these repository secrets in GitHub (`Settings` -> `Secrets and variables` -> `Actions`):

## Bitbucket

- `BITBUCKET_USERNAME`  
  Bitbucket username (example: `digitechafrica`).
- `BITBUCKET_APP_PASSWORD`  
  Bitbucket App Password with at least repository read/write permissions.
- `BITBUCKET_FRONTEND_REPO`  
  Frontend repo path in `workspace/repo` format (example: `digitechafrica/noya_frontend`).
- `BITBUCKET_BACKEND_REPO`  
  Backend repo path in `workspace/repo` format (example: `digitechafrica/noya_web`).

## GitHub backend mirror

- `GITHUB_BACKEND_REPO`  
  Backend repo path in `owner/repo` format (example: `Marseven/noya_web`).
- `GITHUB_BACKEND_PAT`  
  GitHub PAT allowed to push on the backend repository (`repo` scope or fine-grained write access on contents).

## Hostinger

- `HOSTINGER_HOST`  
  Server host (example: `185.206.161.118`).
- `HOSTINGER_PORT`  
  SSH port (example: `65002`).
- `HOSTINGER_USER`  
  SSH username (example: `u626597620`).
- `HOSTINGER_PASSWORD`  
  SSH password.
- `HOSTINGER_FRONT_DIR`  
  Frontend target directory (example: `/home/u626597620/domains/mebodorichard.com/public_html/noya-admin`).
- `HOSTINGER_BACK_DIR`  
  Backend target directory (example: `/home/u626597620/domains/mebodorichard.com/public_html/noya`).

## Trigger

The pipeline runs automatically on:

- push to `main`
- manual trigger (`workflow_dispatch`) from GitHub Actions UI
