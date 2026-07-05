# Dns

List DNS records for a domain.

```bash
rocket dns --team=<team> --domain=<domain>           # DNS
rocket dns --create --team=<team> --domain=<domain> -F type=<type> -F name=<name> -F content=<content> # Create DNS records
rocket dns --update --team=<team> --domain=<domain> --id=<id> # Update DNS records
rocket dns --delete --team=<team> --domain=<domain> --id=<id> # confirms; --force to skip
rocket dns --action=clear --team=<team> --domain=<domain> # confirms; --force to skip
rocket dns --action=template --team=<team> --domain=<domain> -F records=<records> # DNS template
```

[← All commands](README.md)

