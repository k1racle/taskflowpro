const fs = require('fs');

const files = [
  'assets/js/api.js',
  'assets/js/app_combined.js',
];

for (const file of files) {
  const source = fs.readFileSync(file, 'utf8');
  new Function(source);
  console.log(`OK ${file}`);
}
