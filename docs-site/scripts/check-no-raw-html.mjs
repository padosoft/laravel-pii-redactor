import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, extname } from 'node:path';

const root = process.cwd();
const docsDir = join(root, 'docs');
const siteDir = join(root, '_site');
const failures = [];

function walk(dir, predicate, visit) {
  for (const name of readdirSync(dir)) {
    const path = join(dir, name);
    const stat = statSync(path);
    if (stat.isDirectory()) {
      walk(path, predicate, visit);
      continue;
    }
    if (predicate(path)) {
      visit(path, readFileSync(path, 'utf8'));
    }
  }
}

walk(docsDir, (path) => extname(path) === '.md', (path, content) => {
  if (/<[A-Za-z][^>]*>/.test(content)) {
    failures.push(`${path}: raw HTML is not allowed in Markdown docs`);
  }
});

if (existsSync(siteDir)) {
  walk(siteDir, (path) => extname(path) === '.html', (path, content) => {
    if (content.includes(':::')) {
      failures.push(`${path}: visible docmd container marker leaked into HTML`);
    }
  });
}

if (failures.length > 0) {
  console.error(failures.join('\n'));
  process.exit(1);
}

console.log('Markdown and generated HTML guards passed.');
