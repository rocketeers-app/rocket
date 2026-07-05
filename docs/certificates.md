# Certificates

List certificates in a team or on a server.

```bash
rocket certificates --team=<team>                    # List Certificates
rocket certificates --team=<team> --server=<server>  # List Certificates
rocket certificates --create --team=<team> -F name=<name> -F private_key=<private_key> -F certificate=<certificate> # Create Certificate
rocket certificates --update --team=<team> --id=<id> # Update Certificate
rocket certificates --delete --team=<team> --id=<id> # confirms; --force to skip
```

[← All commands](README.md)

