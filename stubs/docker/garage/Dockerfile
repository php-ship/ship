# dxflrs/garage:v2.4.1 is FROM scratch -- no shell, so the __GARAGE_RPC_PUBLIC_ADDR__ substitution
# below (see garage.toml's own comment) happens in this separate "config" stage instead, which has
# one, then only the already-substituted file is copied into the real, shell-less final stage.
#
# Pinned to a specific version, not the floating "3" major tag -- a real bug found via an
# independent re-audit: this was the one floating tag the earlier build-input-pinning pass (see
# the main Dockerfile) missed, since this file was only written afterward.
FROM alpine:3.24.2 AS config
ARG GARAGE_RPC_PUBLIC_ADDR=garage:3901
COPY garage.toml /garage.toml
RUN sed -i "s/__GARAGE_RPC_PUBLIC_ADDR__/${GARAGE_RPC_PUBLIC_ADDR}/" /garage.toml

FROM dxflrs/garage:v2.4.1
COPY --from=config /garage.toml /etc/garage.toml
