<?php
/* Copy to ../.hogar-private/stripe-config.php on the Hostinger account.
 * Keep secret keys outside public_html and never commit the real config.
 * Price IDs are public identifiers; secret keys/webhook signing secrets are not.
 */
return [
 'mode'=>'test',
 'billing_enabled'=>false,
 'secret_key'=>'',
 'webhook_secret'=>'',
 'price_ids_test'=>[
  'advisor'=>'price_1UYDZlEc2x5xSI7gyEm9TjOT',
  'advisor_plus'=>'price_1UYDZrEc2x5xSI7g7q2mpYP6',
  'advisor_unlimited'=>'price_1UYDZsEc2x5xSI7gq2OwEGts',
 ],
 'price_ids_live'=>[
  'advisor'=>'price_1UYDjaICz2Enrh7I6BrbfO9f',
  'advisor_plus'=>'price_1UYDjaICz2Enrh7IhtYlpTo0',
  'advisor_unlimited'=>'price_1UYDjcICz2Enrh7IQAj6tW8d',
 ],
];
