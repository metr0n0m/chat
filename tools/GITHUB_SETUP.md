# GitHub Setup

## Current state
- Git repository initialized in `httpdocs` on branch `main`.
- Dedicated SSH key created at `%USERPROFILE%\.ssh\chat_github_ed25519`.
- SSH alias configured in `%USERPROFILE%\.ssh\config` as `github-chat`.

## Public key to add in GitHub
Add this key in GitHub:

```text
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGhCdxVuB7W7+L6D0+f7ZLn9b1Am3KLsHQ5qEy3tRJBm chat-codex-github
```

Recommended location in GitHub:
- `Settings -> SSH and GPG keys -> New SSH key`

## Remote URL format
Use the alias-based SSH URL so the dedicated key is always selected:

```text
git@github-chat:OWNER/REPO.git
```

Example:

```text
git@github-chat:alex/chat.git
```

## Connect the repo
From `httpdocs`:

```powershell
.\tools\git-bootstrap.ps1 -RemoteUrl git@github-chat:OWNER/REPO.git
```

## Routine sync

```powershell
.\tools\git-sync.ps1 -Message "feat: ..."
```

## Restore from GitHub

```powershell
.\tools\git-restore.ps1 -RemoteUrl git@github-chat:OWNER/REPO.git -TargetPath .\restored-chat
```

## Note
- `ssh-agent` is not enabled on this Windows host, so SSH relies on the configured `IdentityFile`.
- After the key is registered in GitHub and the remote exists, `git push` and `git pull` can work without extra approvals in this environment.
