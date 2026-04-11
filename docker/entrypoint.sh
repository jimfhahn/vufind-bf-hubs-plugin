#!/bin/bash
set -e

VUFIND_HOME=/usr/local/vufind
VUFIND_LOCAL_DIR=/vufind-local
export VUFIND_HOME VUFIND_LOCAL_DIR VUFIND_LOCAL_MODULES=BibframeHub

# ── Register BibframeHub in Composer autoloader ──
if [ ! -f "$VUFIND_HOME/composer.local.json" ]; then
  cat > "$VUFIND_HOME/composer.local.json" <<'EOF'
{
  "autoload": {
    "psr-4": {
      "BibframeHub\\": "module/BibframeHub/src/BibframeHub/"
    },
    "classmap": [
      "module/BibframeHub/Module.php"
    ]
  }
}
EOF
  cd "$VUFIND_HOME" && composer dump-autoload --quiet
fi

# ── Start Solr in background ──
echo "Starting Solr..."
# Create solr user for Solr process (Solr refuses to run as root)
if ! id solr &>/dev/null; then
  useradd -r -m -d /usr/local/vufind/solr solr 2>/dev/null || true
fi
chown -R solr:solr "$VUFIND_HOME/solr"
su - solr -s /bin/bash -c "SOLR_ULIMIT_CHECKS=false JAVA_HOME=/usr VUFIND_HOME=$VUFIND_HOME $VUFIND_HOME/solr.sh start"
sleep 3

# ── Wait for MariaDB ──
echo "Waiting for MariaDB..."
for i in $(seq 1 30); do
  if mariadb -h db -u vufind -pvufind -e "SELECT 1" &>/dev/null; then
    echo "MariaDB ready."
    break
  fi
  sleep 1
done

# ── Initialize database if needed ──
TABLE_COUNT=$(mariadb -h db -u vufind -pvufind vufind -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='vufind'" 2>/dev/null || echo "0")
if [ "$TABLE_COUNT" -lt 5 ]; then
  echo "Initializing VuFind database..."
  cd "$VUFIND_HOME"
  mariadb -h db -u root -proot vufind < module/VuFind/sql/mysql.sql
  # Insert migration baseline
  mariadb -h db -u root -proot vufind -e "INSERT IGNORE INTO migrations(name, status, target_version) VALUES ('mysql.sql', 'success', '11.0.2');"
fi

# ── Load test MARC records into Solr ──
RECORD_COUNT=$(curl -s "http://localhost:8983/solr/biblio/select?q=*:*&rows=0&wt=json" 2>/dev/null | grep -o '"numFound":[0-9]*' | grep -o '[0-9]*' || echo "0")
if [ "$RECORD_COUNT" -lt 1 ]; then
  echo "Loading test records into Solr..."
  curl -s -X POST "http://localhost:8983/solr/biblio/update?commit=true" \
    -H "Content-Type: application/json" \
    -d '[
      {
        "id": "test-pandp-001",
        "title": "Pride and prejudice",
        "title_short": "Pride and prejudice",
        "title_full": "Pride and prejudice / by Jane Austen",
        "title_sort": "pride and prejudice",
        "title_auth": "Pride and prejudice",
        "author": "Austen, Jane, 1775-1817",
        "author_sort": "Austen, Jane",
        "format": ["Book"],
        "record_format": "marc",
        "language": ["English"],
        "publishDate": ["1813"],
        "fullrecord": "<?xml version=\"1.0\"?><record xmlns=\"http://www.loc.gov/MARC21/slim\"><leader>01234nam a2200289 a 4500</leader><controlfield tag=\"001\">test-pandp-001</controlfield><controlfield tag=\"008\">130101s1813    enk           000 1 eng d</controlfield><datafield tag=\"100\" ind1=\"1\" ind2=\" \"><subfield code=\"a\">Austen, Jane,</subfield><subfield code=\"d\">1775-1817.</subfield></datafield><datafield tag=\"245\" ind1=\"1\" ind2=\"0\"><subfield code=\"a\">Pride and prejudice /</subfield><subfield code=\"c\">by Jane Austen.</subfield></datafield><datafield tag=\"260\" ind1=\" \" ind2=\" \"><subfield code=\"a\">London :</subfield><subfield code=\"b\">T. Egerton,</subfield><subfield code=\"c\">1813.</subfield></datafield></record>"
      },
      {
        "id": "test-hamlet-001",
        "title": "Hamlet",
        "title_short": "Hamlet",
        "title_full": "Hamlet / by William Shakespeare",
        "title_sort": "hamlet",
        "title_auth": "Hamlet",
        "author": "Shakespeare, William, 1564-1616",
        "author_sort": "Shakespeare, William",
        "format": ["Book"],
        "record_format": "marc",
        "language": ["English"],
        "publishDate": ["1603"],
        "fullrecord": "<?xml version=\"1.0\"?><record xmlns=\"http://www.loc.gov/MARC21/slim\"><leader>01234nam a2200289 a 4500</leader><controlfield tag=\"001\">test-hamlet-001</controlfield><controlfield tag=\"008\">030101s1603    enk           000 1 eng d</controlfield><datafield tag=\"100\" ind1=\"1\" ind2=\" \"><subfield code=\"a\">Shakespeare, William,</subfield><subfield code=\"d\">1564-1616.</subfield></datafield><datafield tag=\"245\" ind1=\"1\" ind2=\"0\"><subfield code=\"a\">Hamlet /</subfield><subfield code=\"c\">by William Shakespeare.</subfield></datafield><datafield tag=\"260\" ind1=\" \" ind2=\" \"><subfield code=\"a\">London :</subfield><subfield code=\"b\">N. Ling,</subfield><subfield code=\"c\">1603.</subfield></datafield></record>"
      },
      {
        "id": "test-gatsby-001",
        "title": "The Great Gatsby",
        "title_short": "The Great Gatsby",
        "title_full": "The Great Gatsby / by F. Scott Fitzgerald",
        "title_sort": "great gatsby",
        "title_auth": "The Great Gatsby",
        "author": "Fitzgerald, F. Scott, 1896-1940",
        "author_sort": "Fitzgerald, F. Scott",
        "format": ["Book"],
        "record_format": "marc",
        "language": ["English"],
        "publishDate": ["1925"],
        "fullrecord": "<?xml version=\"1.0\"?><record xmlns=\"http://www.loc.gov/MARC21/slim\"><leader>01234nam a2200289 a 4500</leader><controlfield tag=\"001\">test-gatsby-001</controlfield><controlfield tag=\"008\">250101s1925    nyu           000 1 eng d</controlfield><datafield tag=\"100\" ind1=\"1\" ind2=\" \"><subfield code=\"a\">Fitzgerald, F. Scott,</subfield><subfield code=\"d\">1896-1940.</subfield></datafield><datafield tag=\"245\" ind1=\"1\" ind2=\"4\"><subfield code=\"a\">The Great Gatsby /</subfield><subfield code=\"c\">by F. Scott Fitzgerald.</subfield></datafield><datafield tag=\"260\" ind1=\" \" ind2=\" \"><subfield code=\"a\">New York :</subfield><subfield code=\"b\">Charles Scribner&apos;s Sons,</subfield><subfield code=\"c\">1925.</subfield></datafield></record>"
      }
    ]'
  echo ""
  echo "Test records loaded."
fi

# ── Set permissions ──
chown -R www-data:www-data /vufind-local/cache

# ── Inject VUFIND_LOCAL_MODULES into Apache config ──
if ! grep -q "VUFIND_LOCAL_MODULES" /vufind-local/httpd-vufind.conf; then
  sed -i 's|SetEnv VUFIND_LOCAL_DIR /vufind-local|SetEnv VUFIND_LOCAL_DIR /vufind-local\n  SetEnv VUFIND_LOCAL_MODULES BibframeHub|' /vufind-local/httpd-vufind.conf
fi

echo "============================================"
echo " VuFind is ready at http://localhost:4567"
echo " Solr admin at http://localhost:8983"
echo "============================================"

# Start Apache in foreground
exec apache2-foreground
