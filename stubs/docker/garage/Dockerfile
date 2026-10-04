# dxflrs/garage:v2.4.1 is FROM scratch -- no shell, so the __GARAGE_RPC_PUBLIC_ADDR__ substitution
# below (see garage.toml's own comment) happens in this separate "config" stage instead, which has
# one, then only the already-substituted file is copied into the real, shell-less final stage.
#
# Pinned to a specific version, not the floating "3" major tag -- same reasoning as every other
# pinned build input in the main Dockerfile: an unpinned tag can silently change what a build
# produces weeks apart with no corresponding change to this repo.
FROM alpine:3.24.2 AS config
ARG GARAGE_RPC_PUBLIC_ADDR=garage:3901
COPY garage.toml /garage.toml
RUN sed -i "s/__GARAGE_RPC_PUBLIC_ADDR__/${GARAGE_RPC_PUBLIC_ADDR}/" /garage.toml

FROM dxflrs/garage:v2.4.1
COPY --from=config /garage.toml /etc/garage.toml
