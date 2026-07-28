# Security Policy

## Supported Versions

Security fixes are released for the latest stable TypeDock version. During the
release-candidate period, update to the newest RC before reporting a problem
that may already be fixed.

## Reporting a Vulnerability

Do not open a public issue for an unpatched vulnerability. Use GitHub's private
security advisory reporting for `typedock/core` and include:

- the affected TypeDock version;
- the installation mode and database driver;
- reproduction steps or a proof of concept;
- the expected impact; and
- any suggested mitigation.

Please avoid accessing data that is not yours and give maintainers a reasonable
opportunity to publish a fix before disclosure.

## Release Integrity

Official Core release packages are accompanied by:

- a SHA-256 digest;
- a minisign signature; and
- channel metadata published from the release workflow.

Zip-managed installations verify both the digest and the pinned minisign
keyring before extracting or applying an update. The keyring contains an
online primary release key and a separate offline recovery key. A release must
not be published until both public keys are embedded in
`src/Update/Trust.php`; the release workflow fails closed while either is
empty or they are identical.

Private keys must never be committed to this repository. The two keys must not
be stored in the same security boundary.

### Release maintainer setup

Generate the dedicated, non-interactive primary key on a trusted machine:

```bash
minisign -G -W -p typedock-release.pub -s typedock-release.key
```

Store the complete `typedock-release.key` file as the
`MINISIGN_PRIVATE_KEY` secret in a protected GitHub Actions Environment named
`release`. Configure a required reviewer and restrict which release tags can
deploy to that Environment. Do not also create this as a repository-level
secret. The `-W` key is intentionally non-interactive because Actions has no
TTY; Environment approval and tag protection are therefore part of the
signing boundary.

On a separate trusted machine, generate a passphrase-encrypted recovery key:

```bash
minisign -G -p typedock-recovery.pub -s typedock-recovery.key
```

Keep the recovery private key out of GitHub and normal development machines.
Store two encrypted copies on separate media or vaults, and keep the
passphrase in a password manager that is not the only location containing the
key file. Test decryption and a throwaway sign/verify operation before each
stable release.

Copy only the base64 public-key lines into:

- `Trust::PRIMARY_MINISIGN_PUBLIC_KEY`
- `Trust::RECOVERY_MINISIGN_PUBLIC_KEY`

The normal release workflow signs with the primary key and verifies that its
signature matches the pinned primary public key. Installed RC6-or-newer
systems accept a valid package signed by either pinned key and record the
matching key ID in `storage/upgrade-state.json` and
`storage/logs/upgrade.log`.

### Lost or compromised primary key

If the primary private key is merely lost, create a new primary key and use
the recovery key to sign the first release that embeds the new primary public
key.

If the primary private key may have been copied:

1. Remove the `MINISIGN_PRIVATE_KEY` Environment secret and disable the
   affected release workflow.
2. Review repository, Environment, tag, release, and channel-branch activity.
3. Create a new primary key.
4. Build a clean emergency release that replaces
   `PRIMARY_MINISIGN_PUBLIC_KEY` with the new public key while retaining the
   existing recovery public key.
5. Sign that package locally with `typedock-recovery.key`, verify it against
   `typedock-recovery.pub`, then publish the package, signature, checksum, and
   channel metadata.
6. Ask every installation to apply the recovery-signed release immediately.
7. Put only the new primary private key into the protected `release`
   Environment and resume normal releases.

There is no retroactive cryptographic revocation for clients that remain on a
release which still trusts a stolen key. Repository/channel control prevents a
stolen signing key alone from publishing an update, but affected users must
install the recovery-signed rotation release to stop trusting that key.

If both private keys or the recovery passphrase are lost, already-installed
clients cannot authenticate a new key automatically; they require a manual
verified upgrade. If both private keys are compromised, stop the channel and
treat the event as a full release-infrastructure incident.

Release candidates ship with `UPDATE_CHANNEL=rc`. Change the
`config.php.example` default back to `stable` when cutting `1.0.0`.
