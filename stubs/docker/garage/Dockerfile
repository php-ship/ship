# dxflrs/garage is FROM scratch, with no shell, so the __GARAGE_RPC_PUBLIC_ADDR__ substitution
# (see garage.toml) happens in this "config" stage and the result is copied into the final one.
# Both images are pinned to exact versions.
FROM alpine:3.24.2 AS config
ARG GARAGE_RPC_PUBLIC_ADDR=garage:3901
COPY garage.toml /garage.toml
RUN sed -i "s/__GARAGE_RPC_PUBLIC_ADDR__/${GARAGE_RPC_PUBLIC_ADDR}/" /garage.toml

FROM dxflrs/garage:v2.4.1
COPY --from=config /garage.toml /etc/garage.toml
