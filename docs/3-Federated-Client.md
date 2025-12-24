# Federated Client

## PKI

Sample commands to generate a private / public key pair without a passphrase
(adjust key name as wished):

```bash
openssl genrsa -out keys/rsa-federated-client-01.key 3072
openssl rsa -in keys/rsa-federated-client-01.key -pubout -out keys/rsa-federated-client-01.pub
```

