# Akcent Wholesaler Integration

## Summery

The client has three wholesaler integrations:

- JS Wholesaler
- Mada Wholesaler
- Aren Wholesaler

The wholesaler provides products data in xml format. we have to parse the xml and insert to db. then import/update the products in wordpress woocomemrce. here's some twist: we have to filter the products by brands, categories. and we need to map the wholesaler product data to wordpress woocommerce product data.

## Callenges:

the xml files are too large, while we try to parse and log to and work with them we have faced some challenges: memory exe