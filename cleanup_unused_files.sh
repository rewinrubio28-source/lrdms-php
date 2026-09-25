#!/usr/bin/env bash
# Run this from the root of your lrdms-php repo (where Dockerfile lives).
# Removes files confirmed to be unreferenced by any live PHP page.
# Uses `git rm -r` so the deletion is tracked; falls back to `rm` if not a git repo.

set -e

RM="rm -rf"
if [ -d .git ]; then
  RM="git rm -rq"
fi

FILES=(
  # Unused Arsha template demo pages (no PHP page links to these)
  "Arsha/404.html"
  "Arsha/blog.html"
  "Arsha/blog-details.html"
  "Arsha/index.html"
  "Arsha/portfolio-details.html"
  "Arsha/service-details.html"
  "Arsha/starter-page.html"
  "Arsha/Readme.txt"
  "Arsha/forms"                              # only used by the demo pages above
  "Arsha/assets/js/main.js"                  # only loaded by the demo pages above

  # Vendor libraries only used by main.js / the demo pages (not by your live app)
  "Arsha/assets/vendor/isotope-layout"
  "Arsha/assets/vendor/waypoints"
  "Arsha/assets/vendor/imagesloaded"
  "Arsha/assets/vendor/php-email-form"
  "Arsha/assets/vendor/aos/aos.js"
  "Arsha/assets/vendor/aos/aos.cjs.js"
  "Arsha/assets/vendor/aos/aos.esm.js"
  "Arsha/assets/vendor/aos/aos.js.map"
  "Arsha/assets/vendor/glightbox/js"
  "Arsha/assets/vendor/swiper/swiper-bundle.min.js"
  "Arsha/assets/vendor/swiper/swiper-bundle.min.js.map"

  # Bootstrap: you only load bootstrap.min.css + bootstrap.bundle.min.js.
  # Everything else here (source maps, RTL build, unminified, grid-only, utilities-only) is unused.
  "Arsha/assets/vendor/bootstrap/css/bootstrap-reboot.rtl.min.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-utilities.rtl.min.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-grid.rtl.min.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-utilities.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-reboot.rtl.min.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-reboot.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-utilities.rtl.min.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-grid.rtl.min.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap.rtl.min.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-grid.min.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-utilities.rtl.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-grid.rtl.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap.rtl.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-reboot.min.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-grid.rtl.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap.rtl.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap.rtl.min.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-utilities.rtl.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-reboot.rtl.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-reboot.min.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap.min.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-grid.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-grid.min.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-reboot.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-utilities.min.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-grid.css"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-utilities.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-utilities.min.css.map"
  "Arsha/assets/vendor/bootstrap/css/bootstrap-reboot.rtl.css.map"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.js.map"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.js"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.bundle.js"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.min.js"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.esm.js"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.bundle.min.js.map"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.esm.min.js.map"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.min.js.map"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.esm.js.map"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.bundle.js.map"
  "Arsha/assets/vendor/bootstrap/js/bootstrap.esm.min.js"

  # Demo stock photos — you only use img/bg.jpg and "img/manila logo.png"
  "Arsha/assets/img/person"
  "Arsha/assets/img/services"
  "Arsha/assets/img/illustration"
  "Arsha/assets/img/blog"
  "Arsha/assets/img/bg"
  "Arsha/assets/img/portfolio"
  "Arsha/assets/img/cta"
  "Arsha/assets/img/clients"
  "Arsha/assets/img/steps"

  # Orphaned duplicates sitting in the repo root, referenced by nothing
  "bg.jpg"
  "manila logo.png"
)

removed=0
for f in "${FILES[@]}"; do
  if [ -e "$f" ]; then
    $RM "$f"
    echo "removed: $f"
    removed=$((removed+1))
  fi
done

echo ""
echo "Done. Removed $removed items."
if [ "$RM" = "git rm -rq" ]; then
  echo "Review with: git status"
  echo "Then commit: git commit -m \"chore: remove unused Arsha template demo pages and vendor assets\""
fi
