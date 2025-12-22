#!/bin/bash
# SSL Setup Script for XBoard Email Server

echo "=========================================="
echo "SSL Setup for Email Server"
echo "=========================================="
echo ""

# Check if domains point directly to server
DOMAIN_IP=$(dig +short xdexboard.com A | head -1)
MAIL_IP=$(dig +short mail.xdexboard.com A | head -1)
SERVER_IP="152.53.150.194"

if [ "$DOMAIN_IP" != "$SERVER_IP" ]; then
    echo "ERROR: xdexboard.com still points to Cloudflare: $DOMAIN_IP"
    echo "Expected: $SERVER_IP"
    echo "Please disable Cloudflare proxy in Njalla DNS panel"
    exit 1
fi

if [ "$MAIL_IP" != "$SERVER_IP" ]; then
    echo "ERROR: mail.xdexboard.com points to wrong IP: $MAIL_IP"
    echo "Expected: $SERVER_IP"
    exit 1
fi

echo "✓ DNS records point to server correctly"
echo ""

# Stop nginx temporarily
echo "Stopping nginx temporarily..."
sudo systemctl stop nginx

# Get SSL certificate
echo "Getting SSL certificate from Let's Encrypt..."
sudo certbot certonly --standalone --non-interactive --agree-tos \
    --email check@xdexboard.com \
    -d xdexboard.com \
    -d mail.xdexboard.com

if [ $? -eq 0 ]; then
    echo ""
    echo "✓ SSL certificate obtained successfully"
    echo ""
    
    # Start nginx
    sudo systemctl start nginx
    
    # Configure Postfix SSL
    echo "Configuring Postfix SSL..."
    sudo postconf -e "smtpd_tls_cert_file = /etc/letsencrypt/live/xdexboard.com/fullchain.pem"
    sudo postconf -e "smtpd_tls_key_file = /etc/letsencrypt/live/xdexboard.com/privkey.pem"
    sudo postconf -e "smtpd_tls_security_level = may"
    sudo postconf -e "smtpd_tls_auth_only = yes"
    sudo postconf -e "smtp_tls_security_level = may"
    
    # Configure Dovecot SSL
    echo "Configuring Dovecot SSL..."
    sudo sed -i 's/^ssl = no/ssl = required/' /etc/dovecot/dovecot.conf
    echo "" | sudo tee -a /etc/dovecot/dovecot.conf > /dev/null
    echo "ssl_cert = </etc/letsencrypt/live/xdexboard.com/fullchain.pem" | sudo tee -a /etc/dovecot/dovecot.conf > /dev/null
    echo "ssl_key = </etc/letsencrypt/live/xdexboard.com/privkey.pem" | sudo tee -a /etc/dovecot/dovecot.conf > /dev/null
    echo "ssl_protocols = !SSLv2 !SSLv3" | sudo tee -a /etc/dovecot/dovecot.conf > /dev/null
    
    # Restart services
    echo "Restarting services..."
    sudo systemctl restart postfix
    sudo systemctl restart dovecot
    
    echo ""
    echo "=========================================="
    echo "SSL CONFIGURATION COMPLETED!"
    echo "=========================================="
    echo ""
    echo "Services status:"
    sudo systemctl is-active postfix dovecot
    echo ""
    echo "Test SSL:"
    echo "  openssl s_client -connect mail.xdexboard.com:993 -starttls imap"
else
    echo ""
    echo "✗ Failed to obtain SSL certificate"
    sudo systemctl start nginx
    exit 1
fi

