const { loadConfig, runSyncCycle } = require('./sync');

try {
  const config = loadConfig();
  runSyncCycle(config)
    .then((result) => {
      console.log(JSON.stringify(result, null, 2));
      process.exit(result.ok ? 0 : 1);
    })
    .catch((error) => {
      console.error(error.message);
      process.exit(1);
    });
} catch (error) {
  console.error(error.message);
  process.exit(1);
}
