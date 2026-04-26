# NOYA CI/CD Secrets (GitHub Actions)

Create these repository secrets in GitHub (`Settings` -> `Secrets and variables` -> `Actions`):

## Bitbucket

- `BITBUCKET_USERNAME`  
  Optional legacy username (example: `digitechafrica`), not used when `x-token-auth` is enabled.
- `BITBUCKET_BACKEND_TOKEN`  
  Bitbucket token for backend repository push (`noya_web`), used with `x-token-auth`.
- `BITBUCKET_FRONTEND_TOKEN`  
  Bitbucket token for frontend repository clone/push (`noya_frontend`), used with `x-token-auth`.
- `BITBUCKET_FRONTEND_REPO`  
  Frontend repo path in `workspace/repo` format (example: `digitechafrica/noya_frontend`).
- `BITBUCKET_BACKEND_REPO`  
  Backend repo path in `workspace/repo` format (example: `digitechafrica/noya_web`).

Bitbucket token note:

- The workflow authenticates with `https://x-token-auth:<TOKEN>@bitbucket.org/...`.
- If backend and frontend use separate Repository Access Tokens, store both tokens separately.
- If you use one Workspace Access Token for both repos, you can set the same value in both secrets.

## GitHub backend mirror

- `GH_BACKEND_REPO`  
  Backend repo path in `owner/repo` format (example: `Marseven/noya_web`).
- `GH_BACKEND_PAT`  
  GitHub PAT allowed to push on the backend repository (`repo` scope or fine-grained write access on contents).

GitHub Actions limitation:

- Secret names cannot start with `GITHUB_`.  
  Use `GH_BACKEND_REPO` and `GH_BACKEND_PAT`, then map them to internal env vars in the workflow if needed.

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

## Expected 12 secrets

- `BITBUCKET_USERNAME`
- `BITBUCKET_BACKEND_TOKEN`
- `BITBUCKET_FRONTEND_TOKEN`
- `BITBUCKET_FRONTEND_REPO`
- `BITBUCKET_BACKEND_REPO`
- `GH_BACKEND_REPO`
- `GH_BACKEND_PAT`
- `HOSTINGER_HOST`
- `HOSTINGER_PORT`
- `HOSTINGER_USER`
- `HOSTINGER_PASSWORD`
- `HOSTINGER_FRONT_DIR`
- `HOSTINGER_BACK_DIR`
