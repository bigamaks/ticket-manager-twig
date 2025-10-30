const fs = require('fs-extra');
const path = require('path');

// Ensure dist directory exists
fs.ensureDirSync('dist');

// Copy all assets from public to dist
fs.copySync('public', 'dist');

// Convert Twig templates to HTML
const templates = {
  'landing.html.twig': 'index.html',
  'login.html.twig': 'login.html',
  'signup.html.twig': 'signup.html', 
  'dashboard.html.twig': 'dashboard.html',
  'tickets.html.twig': 'tickets.html'
};

Object.entries(templates).forEach(([srcFile, destFile]) => {
  const srcPath = path.join('templates', 'pages', srcFile);
  const destPath = path.join('dist', destFile);
  
  if (fs.existsSync(srcPath)) {
    let content = fs.readFileSync(srcPath, 'utf8');
    
    // Remove server-side Twig syntax
    content = content.replace(/\{\{.*?\}\}/g, '');
    content = content.replace(/\{\%.*?\%\}/g, '');
    content = content.replace(/\{\#.*?\#\}/g, '');
    
    // Fix asset paths
    content = content.replace(/\.\.\/\.\.\/public\//g, '');
    
    fs.writeFileSync(destPath, content);
    console.log(`✓ Converted ${srcFile} → ${destFile}`);
  }
});

console.log('🎉 Static site built successfully!');