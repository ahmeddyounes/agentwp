module.exports = {
  testEnvironment: 'jsdom',
  testMatch: ['<rootDir>/tests/**/*.test.jsx', '<rootDir>/tests/**/*.test.js'],
  transform: {
    '^.+\\.[jt]sx?$': 'babel-jest',
  },
  setupFilesAfterEnv: ['<rootDir>/tests/setupTests.js'],
  moduleNameMapper: {
    '\\.(css|less|scss|sass)$': '<rootDir>/tests/styleMock.js',
  },
  collectCoverage: true,
  collectCoverageFrom: [
    'components/**/*.{js,jsx}',
    '!components/cards/index.js',
  ],
  coverageDirectory: '<rootDir>/coverage',
  coverageThreshold: {
    global: {
      lines: 75,
      statements: 75,
      functions: 70,
      branches: 60,
    },
  },
};
