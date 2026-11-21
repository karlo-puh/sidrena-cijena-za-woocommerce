# Sidrena 2.0 – NN 132/2026 rules, lean (implemented on feature/2.0-pravilnici)

Sources: Pravilnik o načinu objave cjenika proizvoda (NN 132/2026, 1584), Pravilnik o isticanju bazne cijene
proizvoda (NN 132/2026, 1583), in force 17 Nov 2026; Ministry explanation; HOK guidance (SSMM = hours+minutes).
Guiding rule from the maintainer: no overbuilding – nothing with diminishing returns or hard-to-maintain code.

Measured on vstyle (45,214 items): 8–9 full builds a day (~470 queue steps each), CSV 9.9 MB + XML 30.7 MB per
build, 13 GB kept.

## 1. Format (required)
- CSV only, UTF-8 (+BOM setting), ";" fixed. XmlWriter removed; old XML files stay downloadable until they age out.
- Separate files per kind. Goods: naziv;sifra;marka;neto_kolicina;jedinica_mjere;maloprodajna_cijena;
  posebni_oblik_prodaje;cijena_za_jedinicu_mjere;barkod;ean_barkod;bazna_cijena.
  Services: naziv;maloprodajna_cijena;posebni_oblik_prodaje;bazna_cijena. No services file when there are none.
- neto_kolicina = existing unit quantity; a 8/12/13/14-digit numeric barcode goes to ean_barkod, anything else to
  barkod (no new barcode fields); prices always tax-inclusive (tax_mode no longer applies to the list);
  bazna_cijena = the primary base price (the optional second type is not exported).
- Name: {OIB|MB}_{oblik}_{adresa}_{oznaka}_{ROBA|USLUGE}_{broj}_{Ymd_Hi}.csv, or
  {OIB|MB}_ZA_SVA_PRODAJNA_MJESTA_{ROBA|USLUGE}_{broj}_{Ymd_Hi}.csv with "isti cjenik za sva prodajna mjesta".
  Broj = running counter per outlet+kind (outlet "broj pohrane" field retired). Time = publication time.
- New settings: OIB / matični broj (required; without it nothing new is generated and a notice asks for it),
  "isti cjenik za sva prodajna mjesta".

## 2. Publishing (only on change) and archive
- Every run compares the new file's SHA-256 with the current file of the same outlet+kind (and the same seller/
  outlet data); equal → nothing published. First 2.0 file per outlet+kind is always published.
- Status: last check time and last publication time are stored separately.
- Archive: a file is deleted 15 days after a newer file of the same outlet+kind replaced it; the current file is
  never deleted; old combined/XML files count as replaced by the outlet's first 2.0 file. Pruning runs on every run.
  Retention setting min 15, default 15 (a saved larger value is kept).

## 3. Less work (lean)
- Daily 06:00 full build stays (the only full read of all products; catches ERP/off-hook changes), but writes
  only CSV and publishes only if changed. Its collected rows are kept (`rows-full.ndjson`, one line per product
  with its ID, ordered by ID).
- Between full builds, product saves mark the product as changed (postmeta flag; a variable parent marks its
  variations). A debounced job takes up to 200 marked IDs, rebuilds only those rows, merges them into the kept
  rows file, writes the CSVs and publishes if changed. While more marks wait (import, bulk edit) a run only merges
  its batch and starts the next one right away; the last batch publishes, so a burst gives one file
  (from 10:45 to 11:00 every batch publishes; a run with no marks left publishes rows merged earlier). More than
  2,000 marked → one full build instead.
  Stock-only updates are not marked (availability is no longer a column; the 06:00 build covers stock-based
  exclusion). Price changes found by the nightly sweep mark their products the same way.
- Bulk events (snapshot, CSV import, settings change, permanent delete) queue a full build as today.
- Not built: field-level diffing, a database row store, XML/gzip, per-outlet pricing, change revisions.

## 4. Base price (1583)
- Snapshot no longer marks products created after the reference date as "nema" (they need their first-offer
  price); it skips them and says so. First-offer capture unchanged (brand-new products only).
- Wording: legal references NN 101/2026 → NN 132/2026; "08:00 / every day / 30 days" texts updated;
  list column "bazna_cijena". Front-end label unchanged (shop's choice).
- Not built: FMCG automatic rules; retiring the second type (it just isn't exported).

## 5. Notices / URLs
- Deadline notice: daily check scheduled after 10:30, or the last check is older than 26 h (schedule broken).
  A missing OIB/MB surfaces as the generation error notice (no separate notice).
- URLs: /cjenik/latest_roba.csv, /cjenik/latest_usluge.csv (+ per outlet); old latest.csv and latest.xml → the current
  goods list (services for services-only shops); the pre-2.0 file of that format only until the first 2.0 run. Index/JSON list both kinds.

## Release
2.0.0. Upgrade: settings migrated in the sanitizer (services mode → on change, retention min 15, formats/
delimiter/tax_mode ignored). Staging test on the vstyle copy: replay a batch of price changes and measure the
time until the change is in the public CSV, the work per run, and file sizes.

## Review decisions (Codex, 19 Nov)
Fixed: bulk request restarts a build in progress; permanent delete and saved settings queue a full build
(independent of price history; covers entering the OIB after upgrading); the save inside wc_update_product_stock()
is not marked unless out-of-stock products are left out of the list; integration fixtures updated.
Not fixed (not reachable on single-outlet shops / far-fetched): archive timing of removed outlets or of per-outlet
files after switching to a shared list with several outlets; legacy latest.csv alias for a shop whose goods list
became empty while services remain.
