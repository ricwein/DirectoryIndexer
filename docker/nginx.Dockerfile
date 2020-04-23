FROM nginx:latest

LABEL maintainer="Richard Weinhold <docker@ricwein.com>"
LABEL name="nginx-index"

# load our custom nginx config
COPY docker/config/nginx.conf /etc/nginx/conf.d/default.conf

# copy our application to the image
COPY --chown=nginx:nginx . /application
